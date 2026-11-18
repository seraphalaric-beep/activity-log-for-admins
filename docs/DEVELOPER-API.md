# Change Vigil Site Journal developer API

API version 1 lets another WordPress plugin add safe, filterable event types without calling internal database code. The API is available after `plugins_loaded` when Change Vigil Site Journal is active.

## Register an event type

Add the registration filter from the integrating plugin. Use a unique event key with lowercase letters, numbers, and underscores. Choose one of the existing event categories so site owners can use the standard category and individual event controls.

```php
add_filter( 'cvsj_activity_log_external_event_types', static function ( array $types ): array {
    $types['acme_plan_changed'] = [
        'category' => 'components',
        'label' => __( 'Acme plan changed', 'acme-plugin' ),
    ];
    return $types;
} );
```

Supported categories are `content`, `accounts`, `comments`, `taxonomies`, `appearance`, `components`, `settings`, `network`, `database`, `files`, `woocommerce`, `redirection`, `acf`, `bbpress`, `wpforms`, `yoast`, `rank_math`, `pmpro`, `termly`, `mainwp`, and `infinitewp`.

## Record an event

Call the public method after the observed action succeeds. The event type must be registered. Attribution, request source, and time come from WordPress and cannot be supplied by the integration.

```php
if ( class_exists( '\ChangeVigilSiteJournal\DeveloperAPI' ) ) {
    \ChangeVigilSiteJournal\DeveloperAPI::record_event( [
        'event_type' => 'acme_plan_changed',
        'item_kind' => 'setting',
        'item_id' => 'plan',
        'item_name' => 'Acme plan',
        'summary' => 'Acme plan changed',
        'severity' => 'notice',
        'details' => [
            'changes' => [
                'Plan' => [ 'Basic', 'Pro' ],
            ],
        ],
    ] );
}
```

The method returns true when the event row was stored. It returns false for an unregistered type, disabled category or event, excluded actor or item, invalid input, or a storage failure. Recording failure never interrupts the WordPress action.

## Register a custom item kind

Most integrations can use an existing item kind or a public custom content type. Register another item kind only when neither describes the object clearly.

```php
add_filter( 'cvsj_activity_log_item_kinds', static function ( array $kinds ): array {
    $kinds[] = 'acme_record';
    return $kinds;
} );
```

## Data limits

Only these event fields are accepted: `event_type`, `item_kind`, `item_id`, `item_name`, `summary`, `severity`, and `details`. Details accept only a `changes` map with at most twenty labels and a previous value and new value for each label. Unknown fields are rejected. Labels naming passwords, secrets, tokens, authentication, contact details, customer data, request or response bodies, URLs, IP addresses, or sessions are discarded. Email addresses, web addresses, IP addresses, international phone numbers, and values written beside common credential labels are redacted from displayed text.

Never send passwords, credentials, full request or response bodies, personal contact details, payment details, comment text, or other data the site owner would not expect in an audit record. Keep summaries short and factual. The site owner controls retention and can disable the event category or individual type. The event remains local unless the site owner separately configures a report or alert destination.

## Stability

`DeveloperAPI::API_VERSION` is the public interface version. A future incompatible API will use a new version. Do not call `Store`, `Capture`, internal database methods, or plugin database tables from an integration.
