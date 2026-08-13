# XQL Examples

These examples show the pieces of a normal XQL implementation without tying the package to a specific application framework.

The example domain is a race result because it demonstrates the XQL patterns most applications need:

- a root model bound to a database table;
- static referenced files for shared XML instances;
- embedded snapshots for values that should live inside the parent XML file;
- final snapshots for values that should never be mutated after creation;
- repeated child models;
- searchable fields;
- generated/computed fields;
- schema migration declarations;
- manual change notifications and daemon processing.

The model classes are intentionally small. In a real application these would live in your application namespace, for example `App\Classes\XQL`, and your project would set `XQL_MODEL_DIRECTORIES=app/Classes/XQL`.

## Files

- `Models/RaceResult.php` — root XML model bound to a `race_results` table.
- `Models/EventSnapshot.php` — static/final child model used as a referenced immutable event file.
- `Models/CompetitorResult.php` — repeated embedded child model for each competitor result.
- `Models/LapSummary.php` — final embedded child model attached to each competitor.
- `RuntimeUsage.php` — framework-neutral configuration and usage examples.

## Notes

The examples are valid PHP, but calls that create/fetch/process XQL data require configured database connections and storage. Use local storage and test databases when experimenting.

## `static()` and `final()`

`static()` and `final()` control different behavior:

- `static()` means the model persists as its own XML file in object storage. When a static model is attached to another XML document, the parent should store a reference to the static instance, not embed the whole document. Use this for shared files such as events, sessions, racers, organizations, reusable rule templates, or any XML instance that will be fetched independently.
- Without `static()`, attached child models are embedded directly in the parent XML file. Use this for data that is only meaningful with the parent, such as result entries, lap summaries, scoring history frames, and other result-local payloads.
- `final()` means the instance or embedded object is immutable after creation. It is not a signal to create a separate file. It also means the model should not depend on mutable application database bindings for its value after the final XML is created.

Good rule of thumb: use `static()` for independently addressable files; use no `static()` for parent-owned embedded data; use `final()` only when changing the value after creation would be incorrect.
