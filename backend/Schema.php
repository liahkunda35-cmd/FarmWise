<?php

class Schema
{
    public static function install(Database $db): void
    {
        // Skip existing tables (important on Neon: avoid 50+ round-trips per request)
        $existing = array_flip($db->tables());
        $created = 0;
        foreach (self::definitions() as $table => $cols) {
            if (isset($existing[$table])) {
                continue;
            }
            $db->pdo()->exec(self::createSql($db->driver(), $table, $cols));
            $created++;
        }
        // Indexes only when schema is new or tables were added (IF NOT EXISTS is still a network RTT each)
        if ($created > 0 || empty($existing)) {
            self::indexes($db);
        }
    }

    public static function isInstalled(Database $db): bool
    {
        return in_array('users', $db->tables(), true);
    }

    public static function migrate(Database $db): void
    {
        // Ensure any newly added definitions exist (install skips tables already present)
        self::install($db);
        $tables = array_flip($db->tables());
        if (!isset($tables['farms'])) {
            return;
        }
        // One columns() round-trip instead of three hasColumn checks (matters on Neon)
        $farmColSet = array_flip($db->columns('farms'));
        if (!isset($farmColSet['phone'])) {
            $db->pdo()->exec('ALTER TABLE farms ADD COLUMN phone VARCHAR(40) NULL');
        }
        if (!isset($farmColSet['email'])) {
            $db->pdo()->exec('ALTER TABLE farms ADD COLUMN email VARCHAR(160) NULL');
        }
        if (!isset($farmColSet['join_code'])) {
            $db->pdo()->exec('ALTER TABLE farms ADD COLUMN join_code VARCHAR(20) NULL');
        }
        foreach ($db->select("SELECT id FROM farms WHERE join_code IS NULL OR join_code = ''") as $farm) {
            $db->update('farms', ['join_code' => generate_join_code()], $farm['id']);
        }
        // Additive column migrations (safe to re-run)
        self::ensureColumn($db, 'transactions', 'related_id', 'INT NULL');
        self::ensureColumn($db, 'harvests', 'warehouse_id', 'INT NULL');
        self::ensureColumn($db, 'fertilizer_applications', 'inventory_item_id', 'INT NULL');
        self::ensureColumn($db, 'pest_treatments', 'inventory_item_id', 'INT NULL');
        self::ensureColumn($db, 'livestock_feed', 'inventory_item_id', 'INT NULL');
        self::ensureColumn($db, 'spray_schedules', 'inventory_item_id', 'INT NULL');
        self::ensureColumn($db, 'purchase_orders', 'inventory_item_id', 'INT NULL');
        self::ensureColumn($db, 'purchase_orders', 'quotation_id', 'INT NULL');
        self::ensureColumn($db, 'payroll', 'calculation_note', 'TEXT NULL');
        self::ensureColumn($db, 'spray_schedules', 'quantity', 'DECIMAL(12,2) NULL');
        self::ensureColumn($db, 'pest_treatments', 'quantity', 'DECIMAL(12,2) NULL');
        // Task workflow: source link + creator
        self::ensureColumn($db, 'tasks', 'related_module', 'VARCHAR(40) NULL');
        self::ensureColumn($db, 'tasks', 'related_id', 'INT NULL');
        self::ensureColumn($db, 'tasks', 'created_by', 'INT NULL');
        // Phase 2: stock / sales consistency
        self::ensureColumn($db, 'stock_movements', 'created_by', 'INT NULL');
        self::ensureColumn($db, 'stock_movements', 'task_id', 'INT NULL');
        self::ensureColumn($db, 'sales_orders', 'storage_lot_id', 'INT NULL');
        self::ensureColumn($db, 'dispatches', 'sales_order_id', 'INT NULL');
        // Phase 4: reversals audit trail
        try {
            $driver = $db->driver();
            $idCol = $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : ($driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
            $db->pdo()->exec(
                "CREATE TABLE IF NOT EXISTS reversals (
                    id {$idCol},
                    farm_id INT NULL,
                    module VARCHAR(60) NOT NULL,
                    record_id INT NOT NULL,
                    action VARCHAR(40) NOT NULL,
                    reason TEXT NULL,
                    reference VARCHAR(120) NOT NULL,
                    related_reference VARCHAR(120) NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                )"
            );
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_reversals_reference_uq ON reversals (reference)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks (assigned_to)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_employees_user ON employees (user_id)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_stock_movements_reference_uq ON stock_movements (reference)');
        } catch (Throwable $e) {
            // may fail if duplicate nulls / existing dupes — non-fatal
        }
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_storage_lots_harvest_uq ON storage_lots (harvest_id)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_orders_quotation_uq ON purchase_orders (quotation_id) WHERE quotation_id IS NOT NULL");
        } catch (Throwable $e) {
        }
        self::ensureScoutFollowUpIndex($db);
        // Farmer-friendly farm location (no manual lat/lng required)
        self::ensureColumn($db, 'farms', 'country', 'VARCHAR(80) NULL');
        self::ensureColumn($db, 'farms', 'province', 'VARCHAR(120) NULL');
        self::ensureColumn($db, 'farms', 'district', 'VARCHAR(120) NULL');
        self::ensureColumn($db, 'farms', 'town', 'VARCHAR(160) NULL');
        self::ensureColumn($db, 'farms', 'landmark', 'VARCHAR(255) NULL');
        self::ensureColumn($db, 'farms', 'location_precision', "VARCHAR(20) DEFAULT 'none'");
        self::ensureColumn($db, 'farms', 'location_label', 'VARCHAR(255) NULL');
        // Role-based registration / multi-farm assignment
        self::ensureColumn($db, 'users', 'username', 'VARCHAR(80) NULL');
        self::ensureColumn($db, 'users', 'requested_role', 'VARCHAR(40) NULL');
        self::ensureColumn($db, 'users', 'must_change_password', 'INT DEFAULT 0');
        self::ensureColumn($db, 'users', 'activation_token_hash', 'VARCHAR(64) NULL');
        self::ensureColumn($db, 'users', 'activation_expires_at', 'DATETIME NULL');
        self::ensureColumn($db, 'users', 'approved_at', 'DATETIME NULL');
        self::ensureColumn($db, 'users', 'approved_by', 'INT NULL');
        self::ensureColumn($db, 'users', 'rejection_reason', 'TEXT NULL');
        self::ensureColumn($db, 'users', 'session_version', 'INT DEFAULT 1');
        self::ensureColumn($db, 'users', 'notes', 'TEXT NULL');
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users (username)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_user_farms_pair ON user_farms (user_id, farm_id)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_user_farms_user ON user_farms (user_id)');
        } catch (Throwable $e) {
        }
        // Backfill primary farm assignments from users.farm_id
        try {
            foreach (db()->select('SELECT id, farm_id FROM users WHERE farm_id IS NOT NULL') as $u) {
                $exists = db()->first('SELECT id FROM user_farms WHERE user_id = ? AND farm_id = ?', [$u['id'], $u['farm_id']]);
                if (!$exists) {
                    db()->insert('user_farms', [
                        'user_id' => $u['id'],
                        'farm_id' => $u['farm_id'],
                        'is_primary' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_transactions_reference ON transactions (reference)');
        } catch (Throwable $e) {
        }
        try {
            $db->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_reference ON stock_movements (reference)');
        } catch (Throwable $e) {
        }
    }

    public static function ensureScoutFollowUpIndex(Database $db): void
    {
        try {
            $dupes = (int) $db->value(
                "SELECT COUNT(*) FROM (
                    SELECT related_id FROM tasks
                    WHERE related_module = 'pest_scouting' AND related_id IS NOT NULL
                    GROUP BY related_id HAVING COUNT(*) > 1
                ) scout_dupes"
            );
            if ($dupes === 0) {
                $db->pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_scout_followup_uq ON tasks (related_id) WHERE related_module = 'pest_scouting' AND related_id IS NOT NULL");
            }
        } catch (Throwable $e) {
        }
    }

    private static function ensureColumn(Database $db, string $table, string $column, string $definition): void
    {
        try {
            $tables = array_flip($db->tables());
            if (!isset($tables[$table])) {
                return;
            }
            $cols = array_flip($db->columns($table));
            if (isset($cols[$column])) {
                return;
            }
            $db->pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . self::mapType($db->driver(), $definition));
        } catch (Throwable $e) {
            // ignore if concurrent / unsupported
        }
    }

    private static function createSql(string $driver, string $table, array $cols): string
    {
        $lines = [];
        foreach ($cols as $name => $type) {
            $lines[] = $name . ' ' . self::mapType($driver, $type);
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', $lines) . ')';
        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }
        return $sql;
    }

    private static function mapType(string $driver, string $type): string
    {
        if ($driver === 'mysql') {
            return str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $type);
        }
        if ($driver === 'pgsql') {
            $type = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'SERIAL PRIMARY KEY', $type);
            $type = str_replace(['LONGTEXT', 'DATETIME'], ['TEXT', 'TIMESTAMP'], $type);
            $type = preg_replace('/ENUM\([^)]+\)/', 'VARCHAR(40)', $type) ?? $type;
            return $type;
        }
        $type = str_replace('LONGTEXT', 'TEXT', $type);
        $type = preg_replace('/ENUM\([^)]+\)/', 'VARCHAR(40)', $type);
        return $type;
    }

    private static function indexes(Database $db): void
    {
        $indexes = [
            ['users', 'email'],
            ['fields', 'farm_id'],
            ['plantings', 'farm_id'],
            ['plantings', 'field_id'],
            ['animals', 'farm_id'],
            ['inventory_items', 'farm_id'],
            ['harvests', 'farm_id'],
            ['sales_orders', 'farm_id'],
            ['transactions', 'farm_id'],
            ['notifications', 'user_id'],
            ['tasks', 'assigned_to'],
            ['password_resets', 'user_id'],
            ['password_resets', 'token_hash'],
            ['attachments', 'record_id'],
            ['farms', 'join_code'],
        ];
        try {
            $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_settings_key ON settings (setting_key)');
        } catch (Throwable $e) {
        }
        foreach ($indexes as [$table, $col]) {
            $name = 'idx_' . $table . '_' . $col;
            try {
                $db->pdo()->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $col . ')');
            } catch (Throwable $e) {
                // ignore existing index differences
            }
        }
    }

    public static function definitions(): array
    {
        $id = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ts = 'DATETIME NULL';
        $now = 'DATETIME NULL';

        return [
            'users' => [
                'id' => $id,
                'name' => 'VARCHAR(120) NOT NULL',
                'username' => 'VARCHAR(80) NULL',
                'email' => 'VARCHAR(160) NOT NULL',
                'password' => 'VARCHAR(255) NOT NULL',
                'role' => "ENUM('admin','owner','manager','agronomist','worker','accountant') NOT NULL",
                'requested_role' => 'VARCHAR(40) NULL',
                'phone' => 'VARCHAR(40) NULL',
                'farm_id' => 'INT NULL',
                'status' => "VARCHAR(20) DEFAULT 'pending'",
                'must_change_password' => 'INT DEFAULT 0',
                'activation_token_hash' => 'VARCHAR(64) NULL',
                'activation_expires_at' => 'DATETIME NULL',
                'approved_at' => $ts,
                'approved_by' => 'INT NULL',
                'rejection_reason' => 'TEXT NULL',
                'session_version' => 'INT DEFAULT 1',
                'notes' => 'TEXT NULL',
                'last_login' => $ts,
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'user_farms' => [
                'id' => $id,
                'user_id' => 'INT NOT NULL',
                'farm_id' => 'INT NOT NULL',
                'is_primary' => 'INT DEFAULT 0',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'farms' => [
                'id' => $id,
                'name' => 'VARCHAR(160) NOT NULL',
                'owner_id' => 'INT NULL',
                'location' => 'VARCHAR(255) NULL',
                'country' => 'VARCHAR(80) NULL',
                'province' => 'VARCHAR(120) NULL',
                'district' => 'VARCHAR(120) NULL',
                'town' => 'VARCHAR(160) NULL',
                'landmark' => 'VARCHAR(255) NULL',
                'location_precision' => "VARCHAR(20) DEFAULT 'none'",
                'location_label' => 'VARCHAR(255) NULL',
                'latitude' => 'DECIMAL(10,7) NULL',
                'longitude' => 'DECIMAL(10,7) NULL',
                'total_area' => 'DECIMAL(12,2) DEFAULT 0',
                'phone' => 'VARCHAR(40) NULL',
                'email' => 'VARCHAR(160) NULL',
                'join_code' => 'VARCHAR(20) NULL',
                'description' => 'TEXT NULL',
                'established_date' => 'DATE NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'fields' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'size_hectares' => 'DECIMAL(10,2) DEFAULT 0',
                'soil_type' => 'VARCHAR(80) NULL',
                'soil_ph' => 'DECIMAL(4,2) NULL',
                'soil_condition' => 'VARCHAR(120) NULL',
                'moisture_level' => 'VARCHAR(60) NULL',
                'latitude' => 'DECIMAL(10,7) NULL',
                'longitude' => 'DECIMAL(10,7) NULL',
                'boundary_notes' => 'TEXT NULL',
                'land_use' => 'VARCHAR(120) NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'soil_records' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NOT NULL',
                'sample_date' => 'DATE NOT NULL',
                'soil_type' => 'VARCHAR(80) NULL',
                'ph_level' => 'DECIMAL(4,2) NULL',
                'organic_matter' => 'DECIMAL(6,2) NULL',
                'nitrogen' => 'DECIMAL(8,2) NULL',
                'phosphorus' => 'DECIMAL(8,2) NULL',
                'potassium' => 'DECIMAL(8,2) NULL',
                'moisture' => 'DECIMAL(6,2) NULL',
                'condition_notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'crop_rotations' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NOT NULL',
                'season' => 'VARCHAR(40) NULL',
                'previous_crop' => 'VARCHAR(80) NULL',
                'current_crop' => 'VARCHAR(80) NULL',
                'next_crop' => 'VARCHAR(80) NULL',
                'start_date' => 'DATE NULL',
                'end_date' => 'DATE NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'land_use_plans' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NULL',
                'plan_name' => 'VARCHAR(160) NOT NULL',
                'intended_use' => 'VARCHAR(120) NULL',
                'area_hectares' => 'DECIMAL(10,2) NULL',
                'start_date' => 'DATE NULL',
                'end_date' => 'DATE NULL',
                'objectives' => 'TEXT NULL',
                'status' => "VARCHAR(20) DEFAULT 'planned'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'crop_varieties' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'variety' => 'VARCHAR(120) NULL',
                'category' => 'VARCHAR(80) NULL',
                'growing_days' => 'INT NULL',
                'typical_yield_per_ha' => 'DECIMAL(10,2) NULL',
                'description' => 'TEXT NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'plantings' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NOT NULL',
                'crop_variety_id' => 'INT NOT NULL',
                'season' => 'VARCHAR(40) NULL',
                'planting_date' => 'DATE NOT NULL',
                'expected_harvest' => 'DATE NULL',
                'seed_quantity' => 'DECIMAL(10,2) NULL',
                'seed_unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'planting_density' => 'VARCHAR(80) NULL',
                'spacing' => 'VARCHAR(80) NULL',
                'growth_stage' => 'VARCHAR(60) DEFAULT \'seedling\'',
                'status' => "VARCHAR(20) DEFAULT 'planted'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'fertilizer_applications' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'planting_id' => 'INT NOT NULL',
                'field_id' => 'INT NULL',
                'product_name' => 'VARCHAR(160) NOT NULL',
                'type' => 'VARCHAR(40) DEFAULT \'fertilizer\'',
                'quantity' => 'DECIMAL(10,2) NULL',
                'unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'application_date' => 'DATE NOT NULL',
                'method' => 'VARCHAR(80) NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'crop_growth' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'planting_id' => 'INT NOT NULL',
                'record_date' => 'DATE NOT NULL',
                'growth_stage' => 'VARCHAR(80) NULL',
                'height_cm' => 'DECIMAL(8,2) NULL',
                'health_status' => 'VARCHAR(40) DEFAULT \'healthy\'',
                'observations' => 'TEXT NULL',
                'recorded_by' => 'INT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'spray_schedules' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'planting_id' => 'INT NULL',
                'field_id' => 'INT NULL',
                'product_name' => 'VARCHAR(160) NOT NULL',
                'purpose' => 'VARCHAR(120) NULL',
                'scheduled_date' => 'DATE NOT NULL',
                'actual_date' => 'DATE NULL',
                'dosage' => 'VARCHAR(80) NULL',
                'status' => "VARCHAR(20) DEFAULT 'scheduled'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'water_sources' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'source_type' => 'VARCHAR(60) NULL',
                'capacity_litres' => 'DECIMAL(14,2) NULL',
                'quality_notes' => 'TEXT NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'irrigation_systems' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NULL',
                'water_source_id' => 'INT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'system_type' => 'VARCHAR(60) NULL',
                'coverage_hectares' => 'DECIMAL(10,2) NULL',
                'flow_rate' => 'VARCHAR(60) NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'irrigation_schedules' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'irrigation_system_id' => 'INT NOT NULL',
                'field_id' => 'INT NULL',
                'scheduled_date' => 'DATE NOT NULL',
                'start_time' => 'VARCHAR(10) NULL',
                'duration_minutes' => 'INT NULL',
                'water_litres' => 'DECIMAL(12,2) NULL',
                'status' => "VARCHAR(20) DEFAULT 'scheduled'",
                'recommendation' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'water_logs' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'irrigation_system_id' => 'INT NULL',
                'log_date' => 'DATE NOT NULL',
                'water_litres' => 'DECIMAL(12,2) NULL',
                'pump_hours' => 'DECIMAL(8,2) NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'issue_flag' => 'VARCHAR(20) DEFAULT \'none\'',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'breeds' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'species' => 'VARCHAR(80) NOT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'purpose' => 'VARCHAR(80) NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'animals' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'breed_id' => 'INT NULL',
                'tag_number' => 'VARCHAR(80) NOT NULL',
                'name' => 'VARCHAR(120) NULL',
                'species' => 'VARCHAR(80) NULL',
                'gender' => 'VARCHAR(20) NULL',
                'birth_date' => 'DATE NULL',
                'source' => 'VARCHAR(80) NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'current_weight' => 'DECIMAL(8,2) NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'livestock_health' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'animal_id' => 'INT NOT NULL',
                'record_type' => "VARCHAR(30) DEFAULT 'vaccination'",
                'title' => 'VARCHAR(160) NOT NULL',
                'record_date' => 'DATE NOT NULL',
                'next_due' => 'DATE NULL',
                'diagnosis' => 'VARCHAR(160) NULL',
                'treatment' => 'TEXT NULL',
                'vet_name' => 'VARCHAR(120) NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'completed'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'livestock_feed' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'animal_id' => 'INT NULL',
                'feed_name' => 'VARCHAR(160) NOT NULL',
                'quantity' => 'DECIMAL(10,2) NULL',
                'unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'feed_date' => 'DATE NOT NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'livestock_weights' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'animal_id' => 'INT NOT NULL',
                'weigh_date' => 'DATE NOT NULL',
                'weight_kg' => 'DECIMAL(8,2) NOT NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'breeding_records' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'female_id' => 'INT NOT NULL',
                'male_id' => 'INT NULL',
                'breeding_date' => 'DATE NOT NULL',
                'expected_birth' => 'DATE NULL',
                'actual_birth' => 'DATE NULL',
                'offspring_count' => 'INT DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'bred'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'livestock_production' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'animal_id' => 'INT NULL',
                'product_type' => 'VARCHAR(80) NOT NULL',
                'quantity' => 'DECIMAL(10,2) NOT NULL',
                'unit' => 'VARCHAR(20) DEFAULT \'L\'',
                'production_date' => 'DATE NOT NULL',
                'quality_grade' => 'VARCHAR(40) NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'inventory_items' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'category' => 'VARCHAR(60) NOT NULL',
                'sku' => 'VARCHAR(60) NULL',
                'unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'quantity' => 'DECIMAL(12,2) DEFAULT 0',
                'reorder_level' => 'DECIMAL(12,2) DEFAULT 0',
                'unit_cost' => 'DECIMAL(12,2) DEFAULT 0',
                'expiry_date' => 'DATE NULL',
                'location' => 'VARCHAR(120) NULL',
                'status' => "VARCHAR(20) DEFAULT 'in_stock'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'stock_movements' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'inventory_item_id' => 'INT NOT NULL',
                'movement_type' => "VARCHAR(20) NOT NULL",
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'movement_date' => 'DATE NOT NULL',
                'reference' => 'VARCHAR(80) NULL',
                'task_id' => 'INT NULL',
                'created_by' => 'INT NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'equipment' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'category' => 'VARCHAR(80) NULL',
                'asset_code' => 'VARCHAR(60) NULL',
                'purchase_date' => 'DATE NULL',
                'purchase_cost' => 'DECIMAL(12,2) DEFAULT 0',
                'useful_life_years' => 'INT DEFAULT 8',
                'operating_hours' => 'DECIMAL(10,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'operational'",
                'next_service' => 'DATE NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'equipment_logs' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'equipment_id' => 'INT NOT NULL',
                'log_type' => 'VARCHAR(40) NOT NULL',
                'log_date' => 'DATE NOT NULL',
                'hours' => 'DECIMAL(8,2) NULL',
                'fuel_litres' => 'DECIMAL(10,2) NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'allocated_to' => 'VARCHAR(160) NULL',
                'description' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'employees' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'user_id' => 'INT NULL',
                'name' => 'VARCHAR(120) NOT NULL',
                'role_title' => 'VARCHAR(80) NULL',
                'phone' => 'VARCHAR(40) NULL',
                'hire_date' => 'DATE NULL',
                'daily_rate' => 'DECIMAL(10,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'attendance' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'employee_id' => 'INT NOT NULL',
                'work_date' => 'DATE NOT NULL',
                'check_in' => 'VARCHAR(10) NULL',
                'check_out' => 'VARCHAR(10) NULL',
                'hours_worked' => 'DECIMAL(6,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'present'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'tasks' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'title' => 'VARCHAR(160) NOT NULL',
                'description' => 'TEXT NULL',
                'assigned_to' => 'INT NULL',
                'field_id' => 'INT NULL',
                'related_module' => 'VARCHAR(40) NULL',
                'related_id' => 'INT NULL',
                'created_by' => 'INT NULL',
                'due_date' => 'DATE NULL',
                'priority' => "VARCHAR(20) DEFAULT 'normal'",
                'status' => "VARCHAR(20) DEFAULT 'pending'",
                'labour_cost' => 'DECIMAL(10,2) DEFAULT 0',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'payroll' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'employee_id' => 'INT NOT NULL',
                'period_start' => 'DATE NOT NULL',
                'period_end' => 'DATE NOT NULL',
                'days_worked' => 'DECIMAL(6,2) DEFAULT 0',
                'gross_pay' => 'DECIMAL(12,2) DEFAULT 0',
                'deductions' => 'DECIMAL(12,2) DEFAULT 0',
                'net_pay' => 'DECIMAL(12,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'draft'",
                'paid_date' => 'DATE NULL',
                'calculation_note' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'pest_catalog' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'type' => "VARCHAR(20) DEFAULT 'pest'",
                'affected_crops' => 'VARCHAR(255) NULL',
                'symptoms' => 'TEXT NULL',
                'recommended_control' => 'TEXT NULL',
                'severity_default' => 'VARCHAR(20) DEFAULT \'medium\'',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'pest_scouting' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'field_id' => 'INT NOT NULL',
                'planting_id' => 'INT NULL',
                'pest_id' => 'INT NULL',
                'scout_date' => 'DATE NOT NULL',
                'severity' => 'VARCHAR(20) DEFAULT \'low\'',
                'infestation_level' => 'VARCHAR(40) NULL',
                'notes' => 'TEXT NULL',
                'status' => "VARCHAR(20) DEFAULT 'reported'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'pest_treatments' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'scouting_id' => 'INT NULL',
                'field_id' => 'INT NULL',
                'product_name' => 'VARCHAR(160) NOT NULL',
                'treatment_date' => 'DATE NOT NULL',
                'method' => 'VARCHAR(80) NULL',
                'effectiveness' => 'VARCHAR(40) NULL',
                'cost' => 'DECIMAL(12,2) DEFAULT 0',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'weather_logs' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'log_date' => 'DATE NOT NULL',
                'temperature' => 'DECIMAL(6,2) NULL',
                'humidity' => 'DECIMAL(6,2) NULL',
                'rainfall' => 'DECIMAL(8,2) NULL',
                'wind_speed' => 'DECIMAL(6,2) NULL',
                'conditions' => 'VARCHAR(80) NULL',
                'alert_level' => 'VARCHAR(20) DEFAULT \'normal\'',
                'source' => 'VARCHAR(40) DEFAULT \'api\'',
                'raw_json' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'harvests' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'planting_id' => 'INT NOT NULL',
                'field_id' => 'INT NULL',
                'harvest_date' => 'DATE NOT NULL',
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'grade' => 'VARCHAR(40) NULL',
                'quality_notes' => 'TEXT NULL',
                'losses' => 'DECIMAL(12,2) DEFAULT 0',
                'wastage_reason' => 'VARCHAR(160) NULL',
                'storage_location' => 'VARCHAR(160) NULL',
                'warehouse_id' => 'INT NULL',
                'packaging' => 'VARCHAR(80) NULL',
                'expected_quantity' => 'DECIMAL(12,2) NULL',
                'status' => "VARCHAR(20) DEFAULT 'completed'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'customers' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'type' => "VARCHAR(20) DEFAULT 'buyer'",
                'contact_person' => 'VARCHAR(120) NULL',
                'phone' => 'VARCHAR(40) NULL',
                'email' => 'VARCHAR(160) NULL',
                'destination' => 'VARCHAR(160) NULL',
                'address' => 'TEXT NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'market_prices' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'crop_name' => 'VARCHAR(120) NOT NULL',
                'market' => 'VARCHAR(120) NULL',
                'price_date' => 'DATE NOT NULL',
                'price_per_kg' => 'DECIMAL(12,2) NOT NULL',
                'demand_level' => 'VARCHAR(20) DEFAULT \'medium\'',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'sales_orders' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'customer_id' => 'INT NOT NULL',
                'order_number' => 'VARCHAR(40) NOT NULL',
                'order_date' => 'DATE NOT NULL',
                'crop_name' => 'VARCHAR(120) NOT NULL',
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'unit_price' => 'DECIMAL(12,2) NOT NULL',
                'total_amount' => 'DECIMAL(12,2) NOT NULL',
                'storage_lot_id' => 'INT NULL',
                'destination' => 'VARCHAR(160) NULL',
                'status' => "VARCHAR(20) DEFAULT 'pending'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'invoices' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'sales_order_id' => 'INT NULL',
                'customer_id' => 'INT NOT NULL',
                'invoice_number' => 'VARCHAR(40) NOT NULL',
                'invoice_date' => 'DATE NOT NULL',
                'due_date' => 'DATE NULL',
                'amount' => 'DECIMAL(12,2) NOT NULL',
                'status' => "VARCHAR(20) DEFAULT 'unpaid'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'payments' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'invoice_id' => 'INT NULL',
                'customer_id' => 'INT NULL',
                'payment_date' => 'DATE NOT NULL',
                'amount' => 'DECIMAL(12,2) NOT NULL',
                'method' => 'VARCHAR(40) NULL',
                'reference' => 'VARCHAR(80) NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'contracts' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'customer_id' => 'INT NULL',
                'supplier_id' => 'INT NULL',
                'title' => 'VARCHAR(160) NOT NULL',
                'contract_type' => 'VARCHAR(40) DEFAULT \'buyer\'',
                'start_date' => 'DATE NULL',
                'end_date' => 'DATE NULL',
                'crop_name' => 'VARCHAR(120) NULL',
                'agreed_quantity' => 'DECIMAL(12,2) NULL',
                'agreed_price' => 'DECIMAL(12,2) NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'terms' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'transactions' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'txn_date' => 'DATE NOT NULL',
                'type' => "VARCHAR(20) NOT NULL",
                'category' => 'VARCHAR(80) NOT NULL',
                'description' => 'VARCHAR(255) NULL',
                'amount' => 'DECIMAL(12,2) NOT NULL',
                'reference' => 'VARCHAR(80) NULL',
                'related_module' => 'VARCHAR(40) NULL',
                'related_id' => 'INT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'budgets' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'period_start' => 'DATE NOT NULL',
                'period_end' => 'DATE NOT NULL',
                'category' => 'VARCHAR(80) NULL',
                'planned_amount' => 'DECIMAL(12,2) NOT NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'loans' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'lender' => 'VARCHAR(160) NOT NULL',
                'principal' => 'DECIMAL(12,2) NOT NULL',
                'interest_rate' => 'DECIMAL(6,2) DEFAULT 0',
                'start_date' => 'DATE NULL',
                'due_date' => 'DATE NULL',
                'balance' => 'DECIMAL(12,2) NOT NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'suppliers' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'category' => 'VARCHAR(80) NULL',
                'contact_person' => 'VARCHAR(120) NULL',
                'phone' => 'VARCHAR(40) NULL',
                'email' => 'VARCHAR(160) NULL',
                'rating' => 'INT DEFAULT 3',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'quotations' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'supplier_id' => 'INT NOT NULL',
                'quote_number' => 'VARCHAR(40) NOT NULL',
                'item_name' => 'VARCHAR(160) NOT NULL',
                'quantity' => 'DECIMAL(12,2) NULL',
                'unit_price' => 'DECIMAL(12,2) NULL',
                'total' => 'DECIMAL(12,2) NULL',
                'quote_date' => 'DATE NOT NULL',
                'valid_until' => 'DATE NULL',
                'status' => "VARCHAR(20) DEFAULT 'received'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'purchase_orders' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'supplier_id' => 'INT NOT NULL',
                'po_number' => 'VARCHAR(40) NOT NULL',
                'item_name' => 'VARCHAR(160) NOT NULL',
                'category' => 'VARCHAR(80) NULL',
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'unit_price' => 'DECIMAL(12,2) NOT NULL',
                'total' => 'DECIMAL(12,2) NOT NULL',
                'order_date' => 'DATE NOT NULL',
                'status' => "VARCHAR(20) DEFAULT 'pending_approval'",
                'approved_by' => 'INT NULL',
                'payment_status' => "VARCHAR(20) DEFAULT 'unpaid'",
                'quotation_id' => 'INT NULL',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'warehouses' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'name' => 'VARCHAR(160) NOT NULL',
                'type' => "VARCHAR(40) DEFAULT 'warehouse'",
                'capacity' => 'DECIMAL(12,2) NULL',
                'capacity_unit' => 'VARCHAR(20) DEFAULT \'kg\'',
                'temperature' => 'VARCHAR(40) NULL',
                'location' => 'VARCHAR(160) NULL',
                'status' => "VARCHAR(20) DEFAULT 'active'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'storage_lots' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'warehouse_id' => 'INT NOT NULL',
                'harvest_id' => 'INT NULL',
                'produce_name' => 'VARCHAR(160) NOT NULL',
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'grade' => 'VARCHAR(40) NULL',
                'quality' => 'VARCHAR(80) NULL',
                'stored_date' => 'DATE NOT NULL',
                'expected_dispatch' => 'DATE NULL',
                'spoilage' => 'DECIMAL(12,2) DEFAULT 0',
                'unit_value' => 'DECIMAL(12,2) DEFAULT 0',
                'status' => "VARCHAR(20) DEFAULT 'stored'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'dispatches' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'storage_lot_id' => 'INT NOT NULL',
                'sales_order_id' => 'INT NULL',
                'customer_id' => 'INT NULL',
                'dispatch_date' => 'DATE NOT NULL',
                'quantity' => 'DECIMAL(12,2) NOT NULL',
                'destination' => 'VARCHAR(160) NULL',
                'reference' => 'VARCHAR(80) NULL',
                'status' => "VARCHAR(20) DEFAULT 'dispatched'",
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'yield_forecasts' => [
                'id' => $id,
                'farm_id' => 'INT NOT NULL',
                'planting_id' => 'INT NOT NULL',
                'forecast_date' => 'DATE NOT NULL',
                'expected_yield' => 'DECIMAL(12,2) NOT NULL',
                'expected_yield_per_ha' => 'DECIMAL(12,2) NULL',
                'expected_price' => 'DECIMAL(12,2) NULL',
                'expected_revenue' => 'DECIMAL(12,2) NULL',
                'loss_factor' => 'DECIMAL(6,2) DEFAULT 0',
                'factors' => 'TEXT NULL',
                'best_selling_period' => 'VARCHAR(80) NULL',
                'market_risk' => 'VARCHAR(20) DEFAULT \'medium\'',
                'notes' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'notifications' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'user_id' => 'INT NULL',
                'category' => 'VARCHAR(40) NOT NULL',
                'title' => 'VARCHAR(160) NOT NULL',
                'message' => 'TEXT NOT NULL',
                'severity' => "VARCHAR(20) DEFAULT 'info'",
                'is_read' => 'INT DEFAULT 0',
                'link' => 'VARCHAR(160) NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'audit_logs' => [
                'id' => $id,
                'user_id' => 'INT NULL',
                'farm_id' => 'INT NULL',
                'action' => 'VARCHAR(40) NOT NULL',
                'module' => 'VARCHAR(60) NULL',
                'record_id' => 'INT NULL',
                'details' => 'TEXT NULL',
                'ip_address' => 'VARCHAR(60) NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'settings' => [
                'id' => $id,
                'setting_key' => 'VARCHAR(80) NOT NULL',
                'setting_value' => 'TEXT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'password_resets' => [
                'id' => $id,
                'user_id' => 'INT NOT NULL',
                'token_hash' => 'VARCHAR(64) NOT NULL',
                'expires_at' => 'DATETIME NOT NULL',
                'used_at' => $ts,
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'attachments' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'module' => 'VARCHAR(60) NOT NULL',
                'record_id' => 'INT NOT NULL',
                'original_name' => 'VARCHAR(255) NOT NULL',
                'stored_name' => 'VARCHAR(255) NOT NULL',
                'mime' => 'VARCHAR(120) NULL',
                'size_bytes' => 'INT DEFAULT 0',
                'user_id' => 'INT NULL',
                'created_at' => $now,
                'updated_at' => $ts,
            ],
            'outbound_messages' => [
                'id' => $id,
                'farm_id' => 'INT NULL',
                'user_id' => 'INT NULL',
                'notification_id' => 'INT NULL',
                'channel' => 'VARCHAR(20) NOT NULL',
                'recipient' => 'VARCHAR(160) NOT NULL',
                'subject' => 'VARCHAR(180) NULL',
                'body' => 'TEXT NOT NULL',
                'status' => "VARCHAR(20) DEFAULT 'pending'",
                'error' => 'TEXT NULL',
                'sent_at' => $ts,
                'created_at' => $now,
                'updated_at' => $ts,
            ],
        ];
    }
}
