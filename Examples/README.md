# XQL Examples

These examples show the pieces of a normal XQL implementation without tying the package to a specific application framework.

The example domain is a race result because it demonstrates the XQL patterns most applications need:

- a root model bound to a database table;
- static embedded snapshots for values that should be preserved with the XML file;
- final snapshots for values that should never be mutated after creation;
- repeated child models;
- searchable fields;
- generated/computed fields;
- schema migration declarations;
- manual change notifications and daemon processing.

The model classes are intentionally small. In a real application these would live in your application namespace, for example `App\Classes\XQL`, and your project would set `XQL_MODEL_DIRECTORIES=app/Classes/XQL`.

## Files

- `Models/RaceResult.php` — root XML model bound to a `race_results` table.
- `Models/EventSnapshot.php` — static/final child model used as an immutable event snapshot.
- `Models/CompetitorResult.php` — repeated static child model for each competitor result.
- `Models/LapSummary.php` — static/final child model attached to each competitor.
- `RuntimeUsage.php` — framework-neutral configuration and usage examples.

## Notes

The examples are valid PHP, but calls that create/fetch/process XQL data require configured database connections and storage. Use local storage and test databases when experimenting.
