<?php

class Seed
{
    public static function run(): void
    {
        if ((int) db()->value('SELECT COUNT(*) FROM users') > 0) {
            return;
        }

        $farmId = db()->insert('farms', [
            'name' => 'Golden Valley Estate',
            'location' => 'Stellenbosch, Western Cape',
            'latitude' => -33.9321000,
            'longitude' => 18.8602000,
            'total_area' => 128.5,
            'description' => 'Mixed commercial estate producing cereals, horticulture and livestock.',
            'established_date' => '2009-08-01',
            'phone' => '021 555 0100',
            'email' => 'estate@ifms.local',
            'join_code' => generate_join_code(),
            'status' => 'active',
        ]);

        $users = [
            ['Administrator', 'admin@ifms.local', 'Admin#2026', 'admin', null],
            ['Thandi Mokoena', 'owner@ifms.local', 'Owner#2026', 'owner', $farmId],
            ['Johan van Wyk', 'manager@ifms.local', 'Manager#2026', 'manager', $farmId],
            ['Lerato Dlamini', 'agro@ifms.local', 'Agro#2026', 'agronomist', $farmId],
            ['Sipho Ndlovu', 'worker@ifms.local', 'Worker#2026', 'worker', $farmId],
            ['Ayesha Patel', 'accounts@ifms.local', 'Accounts#2026', 'accountant', $farmId],
        ];
        $ids = [];
        foreach ($users as [$name, $email, $pass, $role, $fid]) {
            $ids[$role] = db()->insert('users', [
                'name' => $name,
                'email' => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'role' => $role,
                'phone' => '021 555 01' . rand(10, 99),
                'farm_id' => $fid,
                'status' => 'active',
            ]);
        }
        db()->update('farms', ['owner_id' => $ids['owner']], $farmId);

        $fields = [
            ['North Ridge', 24.0, 'loam', 6.4, 'Good structure', 'adequate', -33.9280, 18.8540, 'Wheat rotation', 'active'],
            ['River Paddock', 18.5, 'clay_loam', 6.1, 'High fertility', 'wet', -33.9360, 18.8680, 'Maize', 'active'],
            ['Valley Blocks', 12.0, 'sandy_loam', 6.8, 'Well drained', 'adequate', -33.9400, 18.8500, 'Vegetables', 'active'],
            ['West Orchard', 9.2, 'loam', 6.5, 'Stable', 'adequate', -33.9250, 18.8720, 'Fruit', 'active'],
            ['Lucerne Camp', 22.0, 'clay', 6.0, 'Compact in winter', 'adequate', -33.9440, 18.8610, 'Forage', 'active'],
        ];
        $fieldIds = [];
        foreach ($fields as $f) {
            $fieldIds[] = db()->insert('fields', [
                'farm_id' => $farmId, 'name' => $f[0], 'size_hectares' => $f[1], 'soil_type' => $f[2],
                'soil_ph' => $f[3], 'soil_condition' => $f[4], 'moisture_level' => $f[5],
                'latitude' => $f[6], 'longitude' => $f[7], 'land_use' => $f[8], 'status' => $f[9],
                'boundary_notes' => 'Mapped boundary with fence line and watercourse.',
            ]);
        }

        db()->insert('soil_records', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[0], 'sample_date' => date('Y-m-d', strtotime('-20 days')),
            'soil_type' => 'loam', 'ph_level' => 6.4, 'organic_matter' => 3.1, 'nitrogen' => 28, 'phosphorus' => 22, 'potassium' => 180, 'moisture' => 18, 'condition_notes' => 'Suitable for winter cereals.',
        ]);
        db()->insert('crop_rotations', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[0], 'season' => '2025/26', 'previous_crop' => 'Canola', 'current_crop' => 'Wheat', 'next_crop' => 'Lucerne', 'start_date' => '2026-05-01', 'notes' => 'Break crop to manage take-all.',
        ]);
        db()->insert('land_use_plans', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[2], 'plan_name' => 'Horticulture intensification', 'intended_use' => 'Drip vegetables', 'area_hectares' => 12, 'start_date' => '2026-01-15', 'status' => 'active', 'objectives' => 'Increase high-value vegetable rotation under drip.',
        ]);

        $crops = [
            ['Wheat', 'SST 806', 'cereal', 150, 4500],
            ['Maize', 'PAN 3P-502', 'cereal', 130, 6200],
            ['Tomato', 'Star 9037', 'vegetable', 90, 38000],
            ['Table grapes', 'Crimson Seedless', 'fruit', 180, 18000],
            ['Lucerne', 'SA Standard', 'forage', 60, 12000],
        ];
        $cropIds = [];
        foreach ($crops as $c) {
            $cropIds[] = db()->insert('crop_varieties', [
                'farm_id' => $farmId, 'name' => $c[0], 'variety' => $c[1], 'category' => $c[2],
                'growing_days' => $c[3], 'typical_yield_per_ha' => $c[4], 'status' => 'active', 'description' => $c[0] . ' commercial variety.',
            ]);
        }

        $plantings = [
            [$fieldIds[0], $cropIds[0], 'Winter 2026', '-80 days', '+70 days', 'seedling', 180, 'kg', '180 kg/ha', '20cm rows', 'flowering', 'growing'],
            [$fieldIds[1], $cropIds[1], 'Summer 2026', '-40 days', '+90 days', 'seed', 22, 'kg', '55,000 plants/ha', '0.9m x 0.25m', 'vegetative', 'growing'],
            [$fieldIds[2], $cropIds[2], 'Spring 2026', '-25 days', '+65 days', 'seedling', 12000, 'plants', '8,000/ha', '1.2m x 0.4m', 'fruiting', 'growing'],
            [$fieldIds[4], $cropIds[4], 'Perennial', '-200 days', '+20 days', 'seed', 18, 'kg', 'broadcast', 'n/a', 'mature', 'growing'],
        ];
        $plantingIds = [];
        foreach ($plantings as $p) {
            $plantingIds[] = db()->insert('plantings', [
                'farm_id' => $farmId, 'field_id' => $p[0], 'crop_variety_id' => $p[1], 'season' => $p[2],
                'planting_date' => date('Y-m-d', strtotime($p[3])), 'expected_harvest' => date('Y-m-d', strtotime($p[4])),
                'seed_quantity' => $p[6], 'seed_unit' => $p[7], 'planting_density' => $p[8], 'spacing' => $p[9],
                'growth_stage' => $p[10], 'status' => $p[11],
            ]);
        }

        db()->insert('fertilizer_applications', [
            'farm_id' => $farmId, 'planting_id' => $plantingIds[0], 'field_id' => $fieldIds[0], 'product_name' => 'LAN 28%',
            'type' => 'fertilizer', 'quantity' => 250, 'unit' => 'kg', 'application_date' => date('Y-m-d', strtotime('-10 days')),
            'method' => 'Broadcast', 'cost' => 4850,
        ]);
        db()->insert('crop_growth', [
            'farm_id' => $farmId, 'planting_id' => $plantingIds[0], 'record_date' => date('Y-m-d', strtotime('-3 days')),
            'growth_stage' => 'flowering', 'height_cm' => 78, 'health_status' => 'healthy', 'observations' => 'Uniform stand, slight aphid pressure on edges.',
        ]);
        db()->insert('spray_schedules', [
            'farm_id' => $farmId, 'planting_id' => $plantingIds[2], 'field_id' => $fieldIds[2], 'product_name' => 'Copper oxychloride',
            'purpose' => 'Blight prevention', 'scheduled_date' => date('Y-m-d', strtotime('+2 days')), 'dosage' => '3 g/L', 'status' => 'scheduled',
        ]);

        $src = db()->insert('water_sources', [
            'farm_id' => $farmId, 'name' => 'Estate borehole 1', 'source_type' => 'borehole', 'capacity_litres' => 45000, 'status' => 'active', 'quality_notes' => 'Low salinity, suitable for drip.',
        ]);
        $sys = db()->insert('irrigation_systems', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[2], 'water_source_id' => $src, 'name' => 'Valley drip block',
            'system_type' => 'drip', 'coverage_hectares' => 12, 'flow_rate' => '4.2 L/h emitters', 'status' => 'active',
        ]);
        db()->insert('irrigation_schedules', [
            'farm_id' => $farmId, 'irrigation_system_id' => $sys, 'field_id' => $fieldIds[2],
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')), 'start_time' => '05:30', 'duration_minutes' => 90, 'water_litres' => 38000, 'status' => 'scheduled',
            'recommendation' => 'Apply if no rainfall is forecast.',
        ]);
        db()->insert('water_logs', [
            'farm_id' => $farmId, 'irrigation_system_id' => $sys, 'log_date' => date('Y-m-d', strtotime('-2 days')),
            'water_litres' => 36000, 'pump_hours' => 3.5, 'cost' => 420, 'issue_flag' => 'none',
        ]);

        $breedC = db()->insert('breeds', ['farm_id' => $farmId, 'species' => 'Cattle', 'name' => 'Bonsmara', 'purpose' => 'Beef']);
        $breedS = db()->insert('breeds', ['farm_id' => $farmId, 'species' => 'Sheep', 'name' => 'Dorper', 'purpose' => 'Meat']);
        $a1 = db()->insert('animals', ['farm_id' => $farmId, 'breed_id' => $breedC, 'tag_number' => 'GV-C-104', 'name' => 'Thandi', 'species' => 'Cattle', 'gender' => 'female', 'birth_date' => '2022-03-12', 'status' => 'active', 'current_weight' => 428]);
        $a2 = db()->insert('animals', ['farm_id' => $farmId, 'breed_id' => $breedC, 'tag_number' => 'GV-C-221', 'name' => 'Duke', 'species' => 'Cattle', 'gender' => 'male', 'birth_date' => '2021-11-02', 'status' => 'active', 'current_weight' => 612]);
        db()->insert('animals', ['farm_id' => $farmId, 'breed_id' => $breedS, 'tag_number' => 'GV-S-018', 'name' => 'Camp ewe', 'species' => 'Sheep', 'gender' => 'female', 'birth_date' => '2023-07-20', 'status' => 'active', 'current_weight' => 52]);
        db()->insert('livestock_health', [
            'farm_id' => $farmId, 'animal_id' => $a1, 'record_type' => 'vaccination', 'title' => 'Anthrax vaccine',
            'record_date' => date('Y-m-d', strtotime('-40 days')), 'next_due' => date('Y-m-d', strtotime('+10 days')), 'status' => 'completed', 'cost' => 85, 'vet_name' => 'Dr. Naidoo',
        ]);
        db()->insert('livestock_feed', ['farm_id' => $farmId, 'feed_name' => 'Lucerne hay', 'quantity' => 40, 'unit' => 'kg', 'feed_date' => date('Y-m-d'), 'cost' => 320]);
        db()->insert('livestock_weights', ['farm_id' => $farmId, 'animal_id' => $a1, 'weigh_date' => date('Y-m-d', strtotime('-7 days')), 'weight_kg' => 428]);
        db()->insert('breeding_records', [
            'farm_id' => $farmId, 'female_id' => $a1, 'male_id' => $a2, 'breeding_date' => date('Y-m-d', strtotime('-90 days')),
            'expected_birth' => date('Y-m-d', strtotime('+190 days')), 'status' => 'pregnant',
        ]);
        db()->insert('livestock_production', [
            'farm_id' => $farmId, 'product_type' => 'milk', 'quantity' => 18, 'unit' => 'L', 'production_date' => date('Y-m-d', strtotime('-1 day')), 'quality_grade' => 'A',
        ]);

        $inv = [];
        $items = [
            ['SST 806 wheat seed', 'seeds', 420, 80, 28, '+180 days'],
            ['LAN 28%', 'fertilizer', 1200, 300, 12.4, null],
            ['Lambda-cyhalothrin', 'chemical', 18, 8, 210, '+40 days'],
            ['Dairy meal', 'feed', 650, 200, 6.8, '+90 days'],
            ['Oxytetracycline', 'veterinary', 12, 4, 95, '+20 days'],
            ['Cartons 10kg', 'packaging', 300, 80, 4.5, null],
            ['Diesel', 'fuel', 1800, 400, 22.1, null],
            ['Pruning shears', 'tools', 14, 4, 185, null],
        ];
        foreach ($items as $it) {
            $inv[] = db()->insert('inventory_items', [
                'farm_id' => $farmId, 'name' => $it[0], 'category' => $it[1], 'sku' => generate_code('SKU'),
                'unit' => $it[1] === 'fuel' ? 'L' : 'kg', 'quantity' => $it[2], 'reorder_level' => $it[3],
                'unit_cost' => $it[4], 'expiry_date' => $it[5] ? date('Y-m-d', strtotime($it[5])) : null,
                'location' => 'Main store', 'status' => $it[2] <= $it[3] ? 'low' : 'in_stock',
            ]);
        }
        db()->insert('stock_movements', [
            'farm_id' => $farmId, 'inventory_item_id' => $inv[0], 'movement_type' => 'out', 'quantity' => 80,
            'movement_date' => date('Y-m-d', strtotime('-80 days')), 'reference' => 'Planting North Ridge',
        ]);

        $eq = db()->insert('equipment', [
            'farm_id' => $farmId, 'name' => 'John Deere 6110B', 'category' => 'tractor', 'asset_code' => 'TR-01',
            'purchase_date' => '2021-04-12', 'purchase_cost' => 980000, 'useful_life_years' => 10,
            'operating_hours' => 1840, 'next_service' => date('Y-m-d', strtotime('+12 days')), 'status' => 'operational',
        ]);
        db()->insert('equipment_logs', [
            'farm_id' => $farmId, 'equipment_id' => $eq, 'log_type' => 'fuel', 'log_date' => date('Y-m-d', strtotime('-3 days')),
            'fuel_litres' => 80, 'hours' => 7.5, 'cost' => 1768, 'allocated_to' => 'North Ridge tillage',
        ]);

        $emp1 = db()->insert('employees', ['farm_id' => $farmId, 'user_id' => $ids['worker'], 'name' => 'Sipho Ndlovu', 'role_title' => 'Field worker', 'phone' => '072 441 2201', 'hire_date' => '2023-02-01', 'daily_rate' => 320, 'status' => 'active']);
        $emp2 = db()->insert('employees', ['farm_id' => $farmId, 'name' => 'Nomsa Khumalo', 'role_title' => 'Irrigation lead', 'phone' => '073 118 9044', 'hire_date' => '2022-09-15', 'daily_rate' => 380, 'status' => 'active']);
        db()->insert('attendance', ['farm_id' => $farmId, 'employee_id' => $emp1, 'work_date' => date('Y-m-d'), 'check_in' => '06:45', 'hours_worked' => 8, 'status' => 'present']);
        db()->insert('tasks', [
            'farm_id' => $farmId, 'title' => 'Scout Valley Blocks for blight', 'assigned_to' => $emp1, 'field_id' => $fieldIds[2],
            'due_date' => date('Y-m-d', strtotime('+1 day')), 'priority' => 'high', 'status' => 'in_progress', 'labour_cost' => 320,
            'description' => 'Walk rows 4-12 and log severity.',
        ]);
        db()->insert('payroll', [
            'farm_id' => $farmId, 'employee_id' => $emp1, 'period_start' => date('Y-m-01', strtotime('-1 month')),
            'period_end' => date('Y-m-t', strtotime('-1 month')), 'days_worked' => 22, 'gross_pay' => 7040, 'deductions' => 200, 'net_pay' => 6840, 'status' => 'paid', 'paid_date' => date('Y-m-d', strtotime('-12 days')),
        ]);

        $pest = db()->insert('pest_catalog', [
            'farm_id' => $farmId, 'name' => 'Early blight', 'type' => 'disease', 'affected_crops' => 'Tomato',
            'symptoms' => 'Concentric leaf lesions, yellowing.', 'recommended_control' => 'Copper spray and remove infected leaves.', 'severity_default' => 'high',
        ]);
        db()->insert('pest_scouting', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[2], 'planting_id' => $plantingIds[2], 'pest_id' => $pest,
            'scout_date' => date('Y-m-d', strtotime('-2 days')), 'severity' => 'medium', 'infestation_level' => '12% plants', 'status' => 'treating', 'notes' => 'Edges of block 2.',
        ]);
        db()->insert('pest_treatments', [
            'farm_id' => $farmId, 'field_id' => $fieldIds[2], 'product_name' => 'Copper oxychloride',
            'treatment_date' => date('Y-m-d', strtotime('-1 day')), 'method' => 'Foliar spray', 'effectiveness' => 'pending', 'cost' => 640,
        ]);

        $hid = db()->insert('harvests', [
            'farm_id' => $farmId, 'planting_id' => $plantingIds[3], 'field_id' => $fieldIds[4], 'harvest_date' => date('Y-m-d', strtotime('-18 days')),
            'quantity' => 18600, 'unit' => 'kg', 'expected_quantity' => 20000, 'grade' => 'A', 'losses' => 740, 'wastage_reason' => 'Weather lodging',
            'storage_location' => 'Hay barn', 'packaging' => 'Bulk', 'status' => 'stored',
        ]);

        $cust = db()->insert('customers', [
            'farm_id' => $farmId, 'name' => 'Cape Fresh Markets', 'type' => 'buyer', 'contact_person' => 'M. Abrahams',
            'phone' => '021 447 1900', 'email' => 'buy@capefresh.co.za', 'destination' => 'Cape Town fresh market', 'status' => 'active',
        ]);
        foreach ([['Wheat', 7.8, 'high'], ['Maize', 5.4, 'medium'], ['Tomato', 14.2, 'high'], ['Lucerne', 3.1, 'medium']] as $i => $mp) {
            db()->insert('market_prices', [
                'farm_id' => $farmId, 'crop_name' => $mp[0], 'market' => 'Cape Town', 'price_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')),
                'price_per_kg' => $mp[1], 'demand_level' => $mp[2],
            ]);
        }
        $so = db()->insert('sales_orders', [
            'farm_id' => $farmId, 'customer_id' => $cust, 'order_number' => 'SO-1042', 'order_date' => date('Y-m-d', strtotime('-8 days')),
            'crop_name' => 'Lucerne', 'quantity' => 4000, 'unit_price' => 3.2, 'total_amount' => 12800, 'destination' => 'Cape Town', 'status' => 'delivered',
        ]);
        $invId = db()->insert('invoices', [
            'farm_id' => $farmId, 'sales_order_id' => $so, 'customer_id' => $cust, 'invoice_number' => 'INV-0881',
            'invoice_date' => date('Y-m-d', strtotime('-8 days')), 'due_date' => date('Y-m-d', strtotime('+6 days')), 'amount' => 12800, 'status' => 'paid',
        ]);
        db()->insert('payments', [
            'farm_id' => $farmId, 'invoice_id' => $invId, 'customer_id' => $cust, 'payment_date' => date('Y-m-d', strtotime('-3 days')),
            'amount' => 12800, 'method' => 'eft', 'reference' => 'EFT-7721',
        ]);
        db()->insert('contracts', [
            'farm_id' => $farmId, 'customer_id' => $cust, 'title' => 'Seasonal lucerne offtake', 'contract_type' => 'contract_farming',
            'start_date' => date('Y-01-01'), 'end_date' => date('Y-12-31'), 'crop_name' => 'Lucerne', 'agreed_quantity' => 20000, 'agreed_price' => 3.1, 'status' => 'active',
            'terms' => 'Monthly offtake, quality grade A, farm-gate collection.',
        ]);

        $tx = [
            ['-8 days', 'income', 'sales', 'Lucerne sale SO-1042', 12800],
            ['-12 days', 'expense', 'labour', 'August payroll', 6840],
            ['-10 days', 'expense', 'input_costs', 'LAN 28%', 4850],
            ['-3 days', 'expense', 'fuel', 'Tractor diesel', 1768],
            ['-2 days', 'expense', 'irrigation', 'Pump electricity', 420],
            ['-1 day', 'expense', 'crop_protection', 'Copper spray', 640],
            ['-20 days', 'income', 'other_income', 'Grant support', 15000],
            ['-30 days', 'expense', 'equipment', 'Service kit', 2400],
        ];
        foreach ($tx as $t) {
            db()->insert('transactions', [
                'farm_id' => $farmId, 'txn_date' => date('Y-m-d', strtotime($t[0])), 'type' => $t[1],
                'category' => $t[2], 'description' => $t[3], 'amount' => $t[4],
            ]);
        }
        db()->insert('budgets', [
            'farm_id' => $farmId, 'name' => 'Q3 production budget', 'category' => 'production',
            'period_start' => date('Y-07-01'), 'period_end' => date('Y-09-30'), 'planned_amount' => 185000,
        ]);
        db()->insert('loans', [
            'farm_id' => $farmId, 'lender' => 'Land Bank', 'principal' => 250000, 'interest_rate' => 9.5,
            'start_date' => date('Y-01-15'), 'due_date' => date('Y-12-15'), 'balance' => 186000, 'status' => 'active',
        ]);

        $sup = db()->insert('suppliers', [
            'farm_id' => $farmId, 'name' => 'AgriChem Cape', 'category' => 'Inputs', 'contact_person' => 'P. Jacobs',
            'phone' => '021 555 4410', 'email' => 'sales@agrichem.example', 'rating' => 4, 'status' => 'active',
        ]);
        db()->insert('quotations', [
            'farm_id' => $farmId, 'supplier_id' => $sup, 'quote_number' => 'Q-3391', 'item_name' => 'LAN 28%',
            'quantity' => 2000, 'unit_price' => 12.1, 'total' => 24200, 'quote_date' => date('Y-m-d', strtotime('-15 days')),
            'valid_until' => date('Y-m-d', strtotime('+15 days')), 'status' => 'accepted',
        ]);
        db()->insert('purchase_orders', [
            'farm_id' => $farmId, 'supplier_id' => $sup, 'po_number' => 'PO-551', 'item_name' => 'LAN 28%',
            'category' => 'fertilizer', 'quantity' => 2000, 'unit_price' => 12.1, 'total' => 24200,
            'order_date' => date('Y-m-d', strtotime('-12 days')), 'status' => 'received', 'payment_status' => 'paid',
        ]);

        $wh = db()->insert('warehouses', [
            'farm_id' => $farmId, 'name' => 'Packhouse cold room', 'type' => 'cold_storage', 'capacity' => 25000, 'capacity_unit' => 'kg',
            'temperature' => '4°C', 'location' => 'Homestead yard', 'status' => 'active',
        ]);
        $lot = db()->insert('storage_lots', [
            'farm_id' => $farmId, 'warehouse_id' => $wh, 'harvest_id' => $hid, 'produce_name' => 'Lucerne bales',
            'quantity' => 14000, 'grade' => 'A', 'quality' => 'Dry, green', 'stored_date' => date('Y-m-d', strtotime('-18 days')),
            'expected_dispatch' => date('Y-m-d', strtotime('+10 days')), 'spoilage' => 120, 'unit_value' => 3.1, 'status' => 'partial',
        ]);
        db()->insert('dispatches', [
            'farm_id' => $farmId, 'storage_lot_id' => $lot, 'customer_id' => $cust, 'dispatch_date' => date('Y-m-d', strtotime('-8 days')),
            'quantity' => 4000, 'destination' => 'Cape Town', 'reference' => 'SO-1042', 'status' => 'delivered',
        ]);

        db()->insert('yield_forecasts', [
            'farm_id' => $farmId, 'planting_id' => $plantingIds[0], 'forecast_date' => date('Y-m-d'),
            'expected_yield' => 98000, 'expected_yield_per_ha' => 4083, 'expected_price' => 7.8,
            'expected_revenue' => 764400, 'loss_factor' => 8, 'factors' => 'Stand establishment good; rust risk moderate.',
            'best_selling_period' => date('M Y', strtotime('+2 months')), 'market_risk' => 'medium',
        ]);

        try {
            NotificationService::syncAlerts();
        } catch (Throwable $e) {
            // Seed data is already written; alert sync is best-effort (e.g. CLI without full app boot)
        }
    }
}
