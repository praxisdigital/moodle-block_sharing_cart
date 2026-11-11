# Changelog

## Unreleased

### Fixed

- `classes/app/backup/backup_settings_helper.php`: `get_course_module_settings()` walked the
  `mod_subsection` tree only one level deep when deciding which sections to include in a Sharing
  Cart backup. For a section nesting two or more levels of `mod_subsection` (e.g. a section
  containing a subsection, which itself contains another subsection), the deepest subsection's own
  module setting was flipped to included, but its own delegated section setting, and any further
  descendant activities, were never reached. This produced an inconsistent backup plan: the
  activity was backed up, but the section container it lives in was not, and the backup dependency
  wiring in Moodle core does not auto-correct that asymmetry. On restore, this caused dozens of
  broken/orphan sections to appear in the destination course. The tree walk now iterates (via a
  queue with a visited-set guard against cycles) to arbitrary nesting depth instead of a single
  pass, so every level's delegated section and descendant activities are included. Refs #243.
