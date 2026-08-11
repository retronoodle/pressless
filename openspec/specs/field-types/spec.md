# Capability: field-types

## Purpose

TBD

## Requirements

### Requirement: Field type contract

The content engine SHALL define a `FieldType` contract that exposes a stable key, human-readable label, schema fragment, value validation, value normalization, the database columns used to persist a value, the binding used when writing an entry row, the binding used when reading an entry row, and the HTML for an admin form control. The contract SHALL be the only place where per-field-type behavior is defined.

#### Scenario: Registry lookup

- **WHEN** application code looks up a field type by its short key (for example `text`, `number`, `date`)
- **THEN** the registry returns the matching implementation with all contract methods callable

#### Scenario: Unknown field type

- **WHEN** application code looks up a field type key that is not registered
- **THEN** the registry raises a safe error identifying the unknown key without crashing the request

### Requirement: Built-in field types

The system SHALL ship the eight built-in field types behind the `FieldType` contract: `text`, `richtext`, `number`, `boolean`, `date`, `select`, `media`, and `relation`. Each SHALL persist its value to the appropriate typed column on `entry_values`, validate input according to its kind, and render a labeled admin form control.

#### Scenario: Text field write and read

- **WHEN** an entry containing a `text` field is saved with the value "Hello" and then read back
- **THEN** the value is stored in `entry_values.value_text` and the repository returns "Hello" with no type coercion

#### Scenario: Number field rejects non-numeric input

- **WHEN** a `number` field is submitted with a non-numeric value
- **THEN** validation returns a field-scoped error and the value is not persisted

#### Scenario: Date field accepts ISO date

- **WHEN** a `date` field is submitted with a value matching the ISO date format
- **THEN** validation succeeds and the value is stored in `entry_values.value_date`

#### Scenario: Boolean field normalizes truthy strings

- **WHEN** a `boolean` field is submitted with a truthy checkbox value (for example `1` or `true`)
- **THEN** the value is normalized to a boolean true before persistence

#### Scenario: Select field validates against configured options

- **WHEN** a `select` field is configured with a fixed list of options and a value outside that list is submitted
- **THEN** validation fails with a field-scoped error

#### Scenario: Media and relation fields render placeholders

- **WHEN** the admin renders a form containing a `media` or `relation` field
- **THEN** the field type renders a clearly labeled placeholder control that does not pretend to store a real reference until later phases wire the pickers

### Requirement: Field type registry is the single source of truth

The system SHALL route every per-field-type decision (schema defaults, validation, persistence, form rendering) through the `FieldTypeRegistry`. Application code outside the registry SHALL NOT branch on field-type identity using `instanceof` or string equality on the type key.

#### Scenario: Collection save validates field set against the registry

- **WHEN** a collection is saved with a field whose `type` is not registered
- **THEN** the save is rejected with a clear error referencing the unknown type
