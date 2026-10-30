# RRRCRM Database Context

> Comprehensive database schema, relationships, constraints, and context for AI agents. This file is meant to be used as persistent context for understanding and working with the RRRCRM database.

**Database Type:** PostgreSQL (DB_CONNECTION=pgsql)  
**Framework:** Laravel 12 with Eloquent ORM  
**Multi-tenant:** Yes - all business data scoped by `tenant_id` via global scopes  
**Last Updated:** 2026-10-30

## Table of Contents

- [Overview](#overview)
- [Core Architecture](#core-architecture)
- [Schema by Domain](#schema-by-domain)
- [Relationships & Foreign Keys](#relationships--foreign-keys)
- [Constraints & Rules](#constraints--rules)
- [Enums & Status Values](#enums--status-values)
- [JSON/Array Fields](#jsonarray-fields)
- [Indexes](#indexes)
- [Notes for AI Agents](#notes-for-ai-agents)

## Overview

RRRCRM (RRRGroupCRM) is a multi-tenant real estate CRM supporting two business modes:
- **Wholesaling** (default): Focus on acquiring distressed properties, assigning contracts to buyers
- **Real Estate Agent/Broker**: Focus on listings, showings, open houses, commissions

All data is scoped to tenants. Users belong to tenants, most business entities have `tenant_id` with cascade deletes.

## Core Architecture

### Multi-Tenancy
- Global scope `TenantScope` applied to most models (leads, deals, properties, buyers, etc.)
- Every query automatically filtered by authenticated user's tenant
- Cross-tenant access prevented at the scope level

### Soft Deletes
- `leads` table uses `SoftDeletes` (has `deleted_at`)
- Most other tables use hard deletes

### Encryption
- Model-level encryption for sensitive fields (see Tenant model): `ai_api_key` encrypted
- API keys and secrets stored securely

## Schema by Domain

### 1. Multi-Tenancy & Authentication

#### `tenants`
Primary workspace/organization table.
```sql
tenants (
  id BIGINT PRIMARY KEY,
  name VARCHAR,
  slug VARCHAR UNIQUE,
  email VARCHAR,
  plan VARCHAR DEFAULT 'starter',
  status VARCHAR DEFAULT 'active',
  business_mode VARCHAR DEFAULT 'wholesale' (wholesale|realestate),
  logo_path VARCHAR,
  timezone VARCHAR,
  currency VARCHAR,
  date_format VARCHAR,
  country VARCHAR,
  measurement_system VARCHAR,
  locale VARCHAR,
  distribution_method VARCHAR,
  claim_window_minutes INTEGER,
  round_robin_index INTEGER,
  timezone_restriction_enabled BOOLEAN DEFAULT false,
  custom_lead_sources JSON,
  custom_options JSON,
  api_key VARCHAR,
  api_enabled BOOLEAN DEFAULT false,
  ai_enabled BOOLEAN DEFAULT false,
  ai_provider VARCHAR,
  ai_api_key TEXT (encrypted),
  ai_model VARCHAR,
  ai_ollama_url VARCHAR,
  ai_custom_url VARCHAR,
  ai_briefings_enabled BOOLEAN DEFAULT false,
  buyer_portal_enabled BOOLEAN DEFAULT false,
  buyer_portal_headline VARCHAR,
  buyer_portal_description TEXT,
  buyer_portal_config JSON,
  notification_preferences JSON,
  default_dashboard_widgets JSON,
  mail_settings JSON,
  require_2fa BOOLEAN DEFAULT false,
  sso_default_driver VARCHAR,
  storage_disk VARCHAR,
  support_email VARCHAR,
  support_phone VARCHAR,
  support_hours VARCHAR,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `roles`
```sql
roles (
  id BIGINT PRIMARY KEY,
  name VARCHAR UNIQUE,
  display_name VARCHAR,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```
Common roles: `admin`, `agent`, `acquisition_agent`, `disposition_agent`, `field_scout`, `listing_agent`, `buyers_agent`

#### `users`
```sql
users (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  role_id BIGINT FK -> roles(id) ON DELETE RESTRICT,
  name VARCHAR,
  email VARCHAR UNIQUE,
  email_verified_at TIMESTAMP NULL,
  password VARCHAR,
  is_active BOOLEAN DEFAULT true,
  remember_token VARCHAR,
  two_factor_secret TEXT (encrypted/nullable),
  two_factor_recovery_codes TEXT (encrypted/nullable),
  two_factor_confirmed_at TIMESTAMP NULL,
  two_factor_provider VARCHAR DEFAULT 'totp',
  onboarding_completed BOOLEAN DEFAULT false,
  calendar_feed_token VARCHAR UNIQUE NULL,
  email_identity VARCHAR,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `permissions`, `role_permission`
```sql
permissions (
  id BIGINT PRIMARY KEY,
  name VARCHAR UNIQUE,
  display_name VARCHAR,
  group VARCHAR,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

role_permission (
  role_id BIGINT FK -> roles(id) ON DELETE CASCADE,
  permission_id BIGINT FK -> permissions(id) ON DELETE CASCADE,
  PRIMARY KEY (role_id, permission_id)
)
```

### 2. Leads & Properties

#### `leads`
```sql
leads (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  first_name VARCHAR,
  last_name VARCHAR,
  phone VARCHAR NULL,
  email VARCHAR NULL,
  lead_source VARCHAR(100) DEFAULT 'other',
  campaign_id BIGINT FK -> campaigns(id) NULL,
  status ENUM('new','prospecting','contacting','engaging','contacted','negotiating','offer_presented','under_contract','dispositions','assigned','closing','closed','closed_won','closed_lost','dead') DEFAULT 'new',
  contact_type VARCHAR NULL,
  temperature ENUM('hot','warm','cold') DEFAULT 'cold',
  motivation_score INTEGER NULL,
  ai_motivation_score INTEGER NULL,
  do_not_contact BOOLEAN DEFAULT false,
  timezone VARCHAR NULL,
  latitude DECIMAL(10,8) NULL,
  longitude DECIMAL(11,8) NULL,
  notes TEXT NULL,
  custom_fields JSON NULL,
  deleted_at TIMESTAMP NULL, -- Soft deletes
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX(tenant_id, status),
  INDEX(tenant_id, agent_id),
  INDEX(tenant_id, created_at)
)
```

#### `properties`
```sql
properties (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE,
  address VARCHAR,
  city VARCHAR,
  state VARCHAR,
  zip_code VARCHAR,
  property_type ENUM('single_family','multi_family','commercial','land','other') DEFAULT 'single_family',
  bedrooms INTEGER NULL,
  bathrooms INTEGER NULL,
  square_feet INTEGER NULL,
  year_built INTEGER NULL,
  lot_size DECIMAL(10,2) NULL,
  estimated_value DECIMAL(12,2) NULL,
  repair_estimate DECIMAL(12,2) NULL,
  after_repair_value DECIMAL(12,2) NULL,
  asking_price DECIMAL(12,2) NULL,
  our_offer DECIMAL(12,2) NULL,
  list_price DECIMAL(12,2) NULL,
  listing_status VARCHAR NULL,
  days_on_market INTEGER NULL,
  distress_markers JSON NULL,
  condition VARCHAR NULL,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `lead_photos`
```sql
lead_photos (
  id BIGINT PRIMARY KEY,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  filename VARCHAR,
  original_name VARCHAR,
  mime_type VARCHAR,
  size INTEGER,
  path VARCHAR,
  caption VARCHAR NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### 3. Deals & Pipeline

#### `deals`
```sql
deals (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE,
  agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  title VARCHAR,
  stage ENUM('new_lead','prospecting','contacting','engaging','contacted','offer_made','offer_presented','negotiating','under_contract','dispositions','assigned','closing','closed_won','closed_lost') DEFAULT 'new_lead',
  contract_price DECIMAL(12,2) NULL,
  assignment_fee DECIMAL(12,2) NULL,
  earnest_money DECIMAL(12,2) NULL,
  inspection_period_days INTEGER NULL,
  contract_date DATE NULL,
  closing_date DATE NULL,
  due_diligence_deadline DATE NULL,
  stage_changed_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX(tenant_id, stage),
  INDEX(tenant_id, agent_id),
  INDEX(closing_date)
)
```

#### `deal_documents`
```sql
deal_documents (
  id BIGINT PRIMARY KEY,
  deal_id BIGINT FK -> deals(id) ON DELETE CASCADE,
  filename VARCHAR,
  original_name VARCHAR,
  mime_type VARCHAR,
  size INTEGER,
  path VARCHAR,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `buyers`
```sql
buyers (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  first_name VARCHAR,
  last_name VARCHAR,
  company_name VARCHAR NULL,
  email VARCHAR NULL,
  phone VARCHAR NULL,
  buyer_type VARCHAR NULL,
  min_price DECIMAL(12,2) NULL,
  max_price DECIMAL(12,2) NULL,
  preferred_locations JSON NULL,
  preferred_property_types JSON NULL,
  cash_buyer BOOLEAN DEFAULT false,
  verified BOOLEAN DEFAULT false,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `buyer_matches`
```sql
buyer_matches (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  deal_id BIGINT FK -> deals(id) ON DELETE CASCADE,
  buyer_id BIGINT FK -> buyers(id) ON DELETE CASCADE,
  status VARCHAR DEFAULT 'matched',
  notified_at TIMESTAMP NULL,
  responded_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### 4. Activities, Tasks & Automation

#### `activities`
```sql
activities (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE NULL,
  deal_id BIGINT FK -> deals(id) ON DELETE CASCADE NULL,
  user_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  type VARCHAR, -- call, email, sms, meeting, note, etc.
  subject VARCHAR,
  description TEXT NULL,
  activity_date TIMESTAMP NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `tasks`
```sql
tasks (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE NULL,
  deal_id BIGINT FK -> deals(id) ON DELETE CASCADE NULL,
  assigned_to BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  created_by BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  title VARCHAR,
  description TEXT NULL,
  due_date DATE NULL,
  priority VARCHAR DEFAULT 'medium',
  status VARCHAR DEFAULT 'pending', -- pending, in_progress, completed, cancelled
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `sequences`, `sequence_steps`, `sequence_enrollments`
```sql
sequences (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  description TEXT NULL,
  active BOOLEAN DEFAULT true,
  created_by BIGINT FK -> users(id) NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

sequence_steps (
  id BIGINT PRIMARY KEY,
  sequence_id BIGINT FK -> sequences(id) ON DELETE CASCADE,
  step_number INTEGER,
  delay_days INTEGER DEFAULT 0,
  delay_hours INTEGER DEFAULT 0,
  action_type VARCHAR, -- email, sms, task, call
  action_config JSON,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

sequence_enrollments (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  sequence_id BIGINT FK -> sequences(id) ON DELETE CASCADE,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE,
  enrolled_by BIGINT FK -> users(id) NULL,
  status VARCHAR DEFAULT 'active', -- active, paused, completed, cancelled
  current_step INTEGER DEFAULT 0,
  next_step_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  enrolled_at TIMESTAMP,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  UNIQUE(sequence_id, lead_id)
)
```

#### `campaigns`
```sql
campaigns (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  source VARCHAR,
  start_date DATE NULL,
  end_date DATE NULL,
  budget DECIMAL(12,2) NULL,
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `workflows`
```sql
workflows (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  description TEXT NULL,
  trigger_type VARCHAR,
  trigger_config JSON NULL,
  actions JSON NULL,
  active BOOLEAN DEFAULT true,
  created_by BIGINT FK -> users(id) NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### 5. Communication & Integrations

#### `notifications`
```sql
notifications (
  id CHAR(36) PRIMARY KEY, -- UUID
  type VARCHAR,
  notifiable_type VARCHAR,
  notifiable_id BIGINT,
  data TEXT,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX(notifiable_type, notifiable_id)
)
```

#### `email_templates`
```sql
email_templates (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  subject VARCHAR,
  body TEXT,
  variables JSON NULL,
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `integrations`
```sql
integrations (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  driver VARCHAR, -- e.g., 'totp', 'sso.google', 'sms.twilio'
  enabled BOOLEAN DEFAULT false,
  config JSON NULL (encrypted where sensitive),
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  UNIQUE(tenant_id, driver)
)
```

#### `api_logs`
```sql
api_logs (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE NULL,
  api_key VARCHAR NULL,
  method VARCHAR,
  path VARCHAR,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  status_code INTEGER,
  response_time_ms INTEGER NULL,
  created_at TIMESTAMP
)
```

#### `webhooks`, `webhook_recipes`
```sql
webhooks (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  url VARCHAR,
  events JSON,
  secret VARCHAR NULL,
  active BOOLEAN DEFAULT true,
  last_triggered_at TIMESTAMP NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

webhook_recipes (
  id BIGINT PRIMARY KEY,
  name VARCHAR,
  description TEXT NULL,
  template JSON,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### 6. Real Estate Agent Mode (Listings, Showings, Open Houses)

#### `listings`
```sql
listings (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  property_id BIGINT FK -> properties(id) ON DELETE CASCADE NULL,
  lead_id BIGINT FK -> leads(id) ON DELETE CASCADE NULL,
  agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  mls_number VARCHAR NULL,
  list_price DECIMAL(12,2),
  status VARCHAR, -- active, pending, sold, expired, withdrawn
  list_date DATE NULL,
  expiration_date DATE NULL,
  commission_percentage DECIMAL(5,2) NULL,
  commission_flat DECIMAL(12,2) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `showings`
```sql
showings (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  listing_id BIGINT FK -> listings(id) ON DELETE CASCADE,
  agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  showing_agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  client_name VARCHAR,
  client_email VARCHAR NULL,
  client_phone VARCHAR NULL,
  scheduled_at TIMESTAMP,
  duration_minutes INTEGER DEFAULT 30,
  status VARCHAR DEFAULT 'scheduled', -- scheduled, confirmed, completed, cancelled, no_show
  feedback TEXT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `open_houses`, `open_house_attendees`
```sql
open_houses (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  listing_id BIGINT FK -> listings(id) ON DELETE CASCADE,
  agent_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  start_time TIMESTAMP,
  end_time TIMESTAMP,
  status VARCHAR DEFAULT 'scheduled',
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

open_house_attendees (
  id BIGINT PRIMARY KEY,
  open_house_id BIGINT FK -> open_houses(id) ON DELETE CASCADE,
  name VARCHAR,
  email VARCHAR NULL,
  phone VARCHAR NULL,
  interested BOOLEAN DEFAULT true,
  follow_up BOOLEAN DEFAULT false,
  notes TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### 7. System, Tags, Customization & Misc

#### `tags`, `taggables` (Polymorphic)
```sql
tags (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  name VARCHAR,
  color VARCHAR DEFAULT '#6c757d',
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  UNIQUE(tenant_id, name)
)

taggables (
  tag_id BIGINT FK -> tags(id) ON DELETE CASCADE,
  taggable_type VARCHAR,
  taggable_id BIGINT,
  PRIMARY KEY (tag_id, taggable_type, taggable_id),
  INDEX(taggable_type, taggable_id)
)
```

#### `custom_field_definitions`
```sql
custom_field_definitions (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  entity_type VARCHAR, -- lead, deal, property, etc.
  field_name VARCHAR,
  field_label VARCHAR,
  field_type VARCHAR, -- text, number, select, date, checkbox
  options JSON NULL,
  required BOOLEAN DEFAULT false,
  order INTEGER DEFAULT 0,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `saved_views`
```sql
saved_views (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE,
  user_id BIGINT FK -> users(id) ON DELETE CASCADE,
  name VARCHAR,
  entity_type VARCHAR, -- leads, deals, buyers
  filters JSON,
  columns JSON NULL,
  is_default BOOLEAN DEFAULT false,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `error_logs`
```sql
error_logs (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE NULL,
  user_id BIGINT FK -> users(id) ON DELETE CASCADE NULL,
  level VARCHAR,
  message TEXT,
  context JSON NULL,
  file VARCHAR NULL,
  line INTEGER NULL,
  trace TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### `audit_logs`
```sql
audit_logs (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT FK -> tenants(id) ON DELETE CASCADE NULL,
  user_id BIGINT FK -> users(id) ON DELETE SET NULL,
  event VARCHAR,
  auditable_type VARCHAR NULL,
  auditable_id BIGINT NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX(auditable_type, auditable_id)
)
```

#### `system_updates`, `system_snapshots`
```sql
system_updates (
  id BIGINT PRIMARY KEY,
  version VARCHAR,
  status VARCHAR,
  backup_path VARCHAR NULL,
  snapshot_id BIGINT NULL,
  notes TEXT NULL,
  executed_at TIMESTAMP NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)

system_snapshots (
  id BIGINT PRIMARY KEY,
  filename VARCHAR,
  path VARCHAR,
  size BIGINT,
  type VARCHAR, -- manual, auto, pre_update
  created_by BIGINT FK -> users(id) NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

#### System tables (Laravel)
```sql
cache (
  key VARCHAR PRIMARY KEY,
  value MEDIUMTEXT,
  expiration INTEGER
)

cache_locks (
  key VARCHAR PRIMARY KEY,
  owner VARCHAR,
  expiration INTEGER
)

jobs (
  id BIGINT PRIMARY KEY,
  queue VARCHAR INDEX,
  payload LONGTEXT,
  attempts TINYINT,
  reserved_at INTEGER NULL,
  available_at INTEGER,
  created_at INTEGER
)

job_batches (
  id VARCHAR PRIMARY KEY,
  name VARCHAR,
  total_jobs INTEGER,
  pending_jobs INTEGER,
  failed_jobs INTEGER,
  failed_job_ids LONGTEXT,
  options MEDIUMTEXT NULL,
  cancelled_at INTEGER NULL,
  created_at INTEGER,
  finished_at INTEGER NULL
)

failed_jobs (
  id BIGINT PRIMARY KEY,
  uuid VARCHAR UNIQUE,
  connection TEXT,
  queue TEXT,
  payload LONGTEXT,
  exception LONGTEXT,
  failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)

sessions (
  id VARCHAR PRIMARY KEY,
  user_id BIGINT INDEX NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  payload LONGTEXT,
  last_activity INTEGER INDEX
)

password_reset_tokens (
  email VARCHAR PRIMARY KEY,
  token VARCHAR,
  created_at TIMESTAMP NULL
)
```

## Relationships & Foreign Keys

### Key Relationships
```
Tenant
├── hasMany Users
├── hasMany Leads
├── hasMany Deals
├── hasMany Buyers
├── hasMany Properties (via leads or directly)
└── hasMany all tenant-scoped entities

User
├── belongsTo Tenant
├── belongsTo Role
├── hasMany Leads (agent_id)
├── hasMany Deals (agent_id)
├── hasMany Activities
├── hasMany Tasks (assigned_to/created_by)
└── hasMany Notifications (polymorphic)

Lead
├── belongsTo Tenant
├── belongsTo User (agent)
├── belongsTo Campaign
├── hasOne Property
├── hasMany Activities
├── hasMany Tasks
├── hasMany Deals
├── hasMany LeadPhotos
├── belongsToMany LeadList (pivot list_leads)
├── hasMany SequenceEnrollments
└── uses SoftDeletes

Deal
├── belongsTo Tenant
├── belongsTo Lead
├── belongsTo User (agent)
├── hasMany DealDocuments
├── hasMany Activities
├── hasMany Tasks
└── hasMany BuyerMatches

Property
├── belongsTo Tenant
├── belongsTo Lead
└── hasOne Listing (in agent mode)

Buyer
├── belongsTo Tenant
└── hasMany BuyerMatches

Sequence
├── belongsTo Tenant
├── hasMany SequenceSteps
└── hasMany SequenceEnrollments

Listing (Agent Mode)
├── belongsTo Tenant
├── belongsTo Property
├── belongsTo Lead
├── belongsTo User (agent)
├── hasMany Showings
└── hasMany OpenHouses
```

### Foreign Key Constraints
- Most tenant-scoped tables: `tenant_id` → `tenants(id)` ON DELETE CASCADE
- `users.tenant_id` → `tenants(id)` ON DELETE CASCADE
- `users.role_id` → `roles(id)` ON DELETE RESTRICT
- `leads.agent_id` → `users(id)` ON DELETE CASCADE (nullable)
- `deals.lead_id` → `leads(id)` ON DELETE CASCADE
- `properties.lead_id` → `leads(id)` ON DELETE CASCADE
- `activities.lead_id`/`deal_id` nullable with CASCADE deletes
- `taggables` uses polymorphic relationship (no FK constraint, just type/id)
- `notifications` uses polymorphic relationship

## Constraints & Rules

### Data Integrity
- **Multi-tenant isolation**: Enforced at application level via `TenantScope` global scope on models
- **Unique constraints**: `tenants.slug`, `users.email`, `tags.(tenant_id,name)`, `sequence_enrollments.(sequence_id,lead_id)`, `integrations.(tenant_id,driver)`, `users.calendar_feed_token`, `roles.name`, `permissions.name`
- **Soft deletes**: Only `leads` use soft deletes
- **Cascading deletes**: Deleting tenant cascades to most related data
- **Role restriction**: Cannot delete roles that are assigned to users (ON DELETE RESTRICT on `users.role_id`)

### Business Rules
- **DNC enforcement**: Leads with `do_not_contact=true` block calls/emails/WhatsApp actions
- **WhatsApp normalization**: Phone numbers normalized using tenant's country dialing code
- **Business mode**: `tenants.business_mode` determines visible modules/terminology (doesn't auto-migrate data on switch)
- **2FA**: Can be enforced globally via `tenants.require_2fa` - users without 2FA redirected to setup
- **API access**: Requires `tenants.api_enabled=true` and valid API key
- **Lead distribution**: Supports round-robin or manual assignment based on `tenants.distribution_method`

## Enums & Status Values

### Lead Status (wholesale)
`new`, `prospecting`, `contacting`, `engaging`, `contacted`, `negotiating`, `offer_presented`, `under_contract`, `dispositions`, `assigned`, `closing`, `closed`, `closed_won`, `closed_lost`, `dead`

### Deal Stages (wholesale)
`new_lead`, `prospecting`, `contacting`, `engaging`, `contacted`, `offer_made`, `offer_presented`, `negotiating`, `under_contract`, `dispositions`, `assigned`, `closing`, `closed_won`, `closed_lost`

### Property Types
`single_family`, `multi_family`, `commercial`, `land`, `other`

### Temperatures
`hot`, `warm`, `cold`

### Task Status
`pending`, `in_progress`, `completed`, `cancelled`

### Showing Status
`scheduled`, `confirmed`, `completed`, `cancelled`, `no_show`

### Sequence Enrollment Status
`active`, `paused`, `completed`, `cancelled`

## JSON/Array Fields

| Table | Field | Purpose |
|---|---|---|
| `tenants` | `custom_lead_sources` | Custom lead source options beyond defaults |
| `tenants` | `custom_options` | Additional tenant configuration |
| `tenants` | `buyer_portal_config` | Buyer portal settings/config |
| `tenants` | `notification_preferences` | Per-notification type preferences |
| `tenants` | `default_dashboard_widgets` | Default dashboard widget layout |
| `tenants` | `mail_settings` | SMTP/mail configuration |
| `leads` | `custom_fields` | Dynamic custom field values |
| `properties` | `distress_markers` | Property distress flags (tax delinquent, pre-foreclosure, etc.) |
| `buyers` | `preferred_locations`, `preferred_property_types` | Buyer preferences |
| `integrations` | `config` | Provider-specific configuration (encrypted sensitive parts) |
| `email_templates` | `variables` | Available template variables |
| `webhooks` | `events` | Events to trigger webhook on |
| `sequence_steps` | `action_config` | Step action configuration |
| `workflows` | `trigger_config`, `actions` | Workflow definition |
| `error_logs` | `context` | Error context data |
| `audit_logs` | `old_values`, `new_values` | Change tracking |
| `saved_views` | `filters`, `columns` | Saved filter/view configuration |

## Indexes

Key indexes for performance (from migration `2026_03_12_230000_add_performance_indexes.php`):
- `leads`: `(tenant_id, status)`, `(tenant_id, agent_id)`, `(tenant_id, created_at)`
- `deals`: `(tenant_id, stage)`, `(tenant_id, agent_id)`, `(closing_date)`
- `activities`: Related indexes for timeline queries
- `tasks`: Indexes for due dates and assignments
- Standard Laravel indexes on foreign keys, queue tables, etc.

## Notes for AI Agents

### Working with the Database
1. **Always respect multi-tenancy** - All business queries must be scoped to tenant. Models use `TenantScope` globally.
2. **Use Eloquent models** - Prefer models over raw queries. Models handle tenant scoping, relationships, casts.
3. **Check business mode** - Features vary between `wholesale` and `realestate` modes. Check `Tenant::isWholesale()` / `isRealEstate()`.
4. **Lead soft deletes** - Leads are soft deleted; use `withTrashed()` if needed.
5. **DNC compliance** - Never contact leads with `do_not_contact=true`. Check before any communication.
6. **Encrypted fields** - API keys, AI keys are encrypted at model level (Laravel encrypted cast).
7. **Roles & permissions** - Check user role/permissions before allowing actions.
8. **JSON fields** - Many settings stored as JSON arrays/objects; use proper casting.

### Common Query Patterns
```php
// Leads for current tenant (auto-scoped)
$leads = Lead::where('status', 'new')->get();

// Include soft deleted
$leads = Lead::withTrashed()->get();

// Deals with relationships
$deal = Deal::with(['lead.property', 'agent', 'documents'])->find($id);
```

### Migration Strategy
The schema has evolved significantly (migrations from 2024 through 2026). Many features added via ALTER TABLE migrations. The full schema is the sum of all migrations - use this document as a consolidated reference.

### API Considerations
- API uses tenant API keys (`tenants.api_key`) for authentication
- Rate limited to 60 requests/minute
- All API endpoints tenant-scoped
- API logs stored in `api_logs` table
