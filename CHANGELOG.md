### ✏️ Changed

- Prepared server for new scheduling:
	- Added a "legacy scheduling" option in study settings. This is activated for existing studies after the update, but deactivated by default for newly created studies.
	- Reworked the calendar to show correct timing for both new and old scheduling versions.
- "_Minutes between_" property is now always shown for random schedules.
- Made "_group_" a reserved key. This prevents accidental loss of data by naming a variable "group", which could get overwritten when multiple experimental groups are used.
- Fixed some language lists being sorted by language codes rather than displayed language names.
- Ensured that users creating a new study also receive reward permission.
- Fixed errors when translatable properties are added only in non-default language.