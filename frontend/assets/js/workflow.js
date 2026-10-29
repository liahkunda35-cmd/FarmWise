/**
 * FarmWise workflow chain — next-step chips for detail pages (Phase 3+).
 * Each chip: { label, module, params?, href? }
 * Chips should only render when the user can create (or open) the target.
 */
(function (global) {
  'use strict';

  const WORKFLOW = {
    farms: [
      { label: 'Add field', module: 'fields', params: { farm_id: ':id' } },
      { label: 'Add warehouse', module: 'warehouses', params: { farm_id: ':id' } },
      { label: 'Add employee', module: 'employees', params: { farm_id: ':id' } },
      { label: 'Set budget', module: 'budgets', params: { farm_id: ':id' } }
    ],
    fields: [
      { label: 'Record soil test', module: 'soil_records', params: { field_id: ':id' } },
      { label: 'Plan rotation', module: 'crop_rotations', params: { field_id: ':id' } },
      { label: 'Add planting', module: 'plantings', params: { field_id: ':id' } },
      { label: 'Add irrigation system', module: 'irrigation_systems', params: { field_id: ':id' } },
      { label: 'Open on map', href: 'map' }
    ],
    crop_varieties: [
      { label: 'Add planting', module: 'plantings', params: { crop_variety_id: ':id' } }
    ],
    plantings: [
      { label: 'Log growth', module: 'crop_growth', params: { planting_id: ':id' } },
      { label: 'Log fertilizer', module: 'fertilizer_applications', params: { planting_id: ':id' } },
      { label: 'Schedule spray', module: 'spray_schedules', params: { planting_id: ':id' } },
      { label: 'Scout for pests', module: 'pest_scouting', params: { planting_id: ':id' } },
      { label: 'Assign task', module: 'tasks', params: { related_module: 'plantings', related_id: ':id' } },
      { label: 'Forecast yield', module: 'yield_forecasts', params: { planting_id: ':id' }, href: 'forecasts' },
      { label: 'Record harvest', module: 'harvests', params: { planting_id: ':id' } }
    ],
    pest_scouting: [
      { label: 'Add treatment', module: 'pest_treatments', params: { scouting_id: ':id' } },
      { label: 'Schedule spray', module: 'spray_schedules', params: { planting_id: ':planting_id' } },
      { label: 'Create task from observation', module: 'tasks', params: { related_module: 'pest_scouting', related_id: ':id', field_id: ':field_id' } }
    ],
    pest_treatments: [
      { label: 'Log growth check', module: 'crop_growth', params: { planting_id: ':planting_id' } }
    ],
    harvests: [
      { label: 'View / edit storage lot', module: 'storage_lots', params: { harvest_id: ':id' } },
      { label: 'Update forecast', href: 'forecasts' }
    ],
    storage_lots: [
      { label: 'Dispatch', module: 'dispatches', params: { storage_lot_id: ':id' } },
      { label: 'Edit lot (spoilage)', module: 'storage_lots', action: 'edit' },
      { label: 'Create sales order', module: 'sales_orders', params: { storage_lot_id: ':id' } }
    ],
    customers: [
      { label: 'New sales order', module: 'sales_orders', params: { customer_id: ':id' } },
      { label: 'New contract', module: 'contracts', params: { customer_id: ':id' } },
      { label: 'New dispatch', module: 'dispatches', params: { customer_id: ':id' } }
    ],
    sales_orders: [
      { label: 'Create invoice', module: 'invoices', params: { sales_order_id: ':id' } },
      { label: 'New dispatch', module: 'dispatches', params: { sales_order_id: ':id' } }
    ],
    invoices: [
      { label: 'Record payment', module: 'payments', params: { invoice_id: ':id' } },
      { label: 'Print / PDF', module: 'invoices', action: 'view' }
    ],
    payments: [
      { label: 'View invoice', module: 'invoices', action: 'view', params: { id: ':invoice_id' } },
      { label: 'View income entry', module: 'transactions' }
    ],
    suppliers: [
      { label: 'New quotation', module: 'quotations', params: { supplier_id: ':id' } },
      { label: 'New purchase order', module: 'purchase_orders', params: { supplier_id: ':id' } }
    ],
    quotations: [
      { label: 'Convert to purchase order', module: 'purchase_orders', params: { quotation_id: ':id' }, when: { status: 'accepted' } }
    ],
    purchase_orders: [
      { label: 'Edit PO (mark received)', module: 'purchase_orders', action: 'edit' },
      { label: 'Stock in linked item', module: 'stock_movements', params: { inventory_item_id: ':inventory_item_id', movement_type: 'in' } }
    ],
    inventory_items: [
      { label: 'Stock in', module: 'stock_movements', params: { inventory_item_id: ':id', movement_type: 'in' } },
      { label: 'Stock out', module: 'stock_movements', params: { inventory_item_id: ':id', movement_type: 'out' } },
      { label: 'Reorder', module: 'purchase_orders', params: { inventory_item_id: ':id' } }
    ],
    irrigation_systems: [
      { label: 'New schedule', module: 'irrigation_schedules', params: { irrigation_system_id: ':id' } },
      { label: 'Log water use', module: 'water_logs', params: { irrigation_system_id: ':id' } },
      { label: 'Log pump maintenance', module: 'equipment_logs' }
    ],
    water_sources: [
      { label: 'Add irrigation system', module: 'irrigation_systems', params: { water_source_id: ':id' } }
    ],
    animals: [
      { label: 'Log vaccination', module: 'livestock_health', params: { animal_id: ':id' } },
      { label: 'Log feed', module: 'livestock_feed', params: { animal_id: ':id' } },
      { label: 'Record weight', module: 'livestock_weights', params: { animal_id: ':id' } },
      { label: 'Record breeding', module: 'breeding_records', params: { animal_id: ':id' } },
      { label: 'Record production', module: 'livestock_production', params: { animal_id: ':id' } }
    ],
    breeding_records: [
      { label: 'Register offspring', module: 'animals', params: { breed_id: ':breed_id' } }
    ],
    employees: [
      { label: 'Mark attendance', module: 'attendance', params: { employee_id: ':id' } },
      { label: 'Assign task', module: 'tasks', params: { assigned_to: ':id' } },
      { label: 'Create payroll entry', module: 'payroll', params: { employee_id: ':id' } }
    ],
    tasks: [
      { label: 'Update status', module: 'tasks', action: 'edit' },
      { label: 'Reassign', module: 'tasks', action: 'edit' }
    ],
    equipment: [
      { label: 'Log usage/fuel', module: 'equipment_logs', params: { equipment_id: ':id', log_type: 'fuel' } },
      { label: 'Log maintenance', module: 'equipment_logs', params: { equipment_id: ':id', log_type: 'maintenance' } },
      { label: 'Log repair', module: 'equipment_logs', params: { equipment_id: ':id', log_type: 'repair' } }
    ]
  };

  function stepsFor(module) {
    return WORKFLOW[module] || [];
  }

  function resolveParam(val, record) {
    if (typeof val === 'string' && val.charAt(0) === ':' && record) {
      const field = val.slice(1);
      return record[field] != null ? record[field] : (field === 'id' ? record.id : '');
    }
    return val;
  }

  function buildUrl(chip, record) {
    const base = (global.IFMS_BASE || '').replace(/\/$/, '');
    if (chip.href) {
      return base + '/' + String(chip.href).replace(/^\//, '');
    }
    if (!chip.module) return null;
    const action = chip.action === 'view' || chip.action === 'edit' ? chip.action : 'create';
    let path = 'module/' + chip.module + '/' + action;
    const params = Object.assign({}, chip.params || {});
    let pathId = null;
    if (action === 'view' || action === 'edit') {
      if (params.id != null) {
        pathId = resolveParam(params.id, record);
        delete params.id;
      } else if (record && record.id) {
        pathId = record.id;
      }
      if (pathId != null && pathId !== '') {
        path += '/' + pathId;
      }
    }
    const qs = [];
    Object.keys(params).forEach((key) => {
      const val = resolveParam(params[key], record);
      if (val !== '' && val != null) {
        qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
      }
    });
    return base + '/' + path + (qs.length ? '?' + qs.join('&') : '');
  }

  global.IFMS_WORKFLOW = {
    map: WORKFLOW,
    stepsFor: stepsFor,
    buildUrl: buildUrl
  };
})(typeof window !== 'undefined' ? window : globalThis);
