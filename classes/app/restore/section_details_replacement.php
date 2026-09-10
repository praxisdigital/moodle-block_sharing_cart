<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;

/**
 * Replaces the title and description of the section a copy merges into.
 *
 * Core only writes the copied title and description into fields that are empty, so the target's fields are blanked
 * (description files included) before the restore runs and core then fills them from the backup as if the section
 * were new. The previous values are kept in a snapshot until the restore has finished, so a failed restore can put
 * them back.
 */
final class section_details_replacement
{
    public const COMPONENT = 'block_sharing_cart';
    public const FILEAREA = 'section_snapshot';

    private const SNAPSHOT_FILENAME = 'section.json';
    private const FILES_PREFIX = '/files';

    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    /**
     * Keep the section's title, description and description files in a snapshot, then blank them.
     */
    public function snapshot_and_blank(int $course_id, int $section_id): void
    {
        $db = $this->base_factory->moodle()->db();
        $fs = get_file_storage();
        $context = \core\context\course::instance($course_id);

        $section = $db->get_record(
            'course_sections',
            ['id' => $section_id, 'course' => $course_id],
            'id, name, summary, summaryformat',
            MUST_EXIST
        );

        $this->discard($course_id, $section_id);

        $fs->create_file_from_string(
            $this->snapshot_record($context->id, $section_id, '/', self::SNAPSHOT_FILENAME),
            json_encode([
                'name' => $section->name,
                'summary' => $section->summary,
                'summaryformat' => $section->summaryformat,
            ])
        );

        foreach ($fs->get_area_files($context->id, 'course', 'section', $section_id, 'id', false) as $file) {
            $fs->create_file_from_storedfile(
                $this->snapshot_record(
                    $context->id,
                    $section_id,
                    self::FILES_PREFIX . $file->get_filepath(),
                    $file->get_filename()
                ),
                $file
            );
        }

        $db->update_record('course_sections', (object)[
            'id' => $section_id,
            'name' => null,
            'summary' => '',
            'timemodified' => time(),
        ]);
        $fs->delete_area_files($context->id, 'course', 'section', $section_id);

        rebuild_course_cache($course_id, true);
    }

    /**
     * Put the snapshot back. Returns false when there is no snapshot for the section.
     */
    public function rollback(int $course_id, int $section_id): bool
    {
        $db = $this->base_factory->moodle()->db();
        $fs = get_file_storage();
        $context = \core\context\course::instance($course_id);

        $snapshot = $fs->get_file(
            $context->id,
            self::COMPONENT,
            self::FILEAREA,
            $section_id,
            '/',
            self::SNAPSHOT_FILENAME
        );
        if (!$snapshot) {
            return false;
        }

        $details = json_decode($snapshot->get_content());

        $fs->delete_area_files($context->id, 'course', 'section', $section_id);
        foreach ($fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, $section_id, 'id', false) as $file) {
            if (!str_starts_with($file->get_filepath(), self::FILES_PREFIX . '/')) {
                continue;
            }
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => 'course',
                'filearea' => 'section',
                'itemid' => $section_id,
                'filepath' => substr($file->get_filepath(), strlen(self::FILES_PREFIX)),
                'filename' => $file->get_filename(),
            ], $file);
        }

        $db->update_record('course_sections', (object)[
            'id' => $section_id,
            'name' => $details->name,
            'summary' => $details->summary,
            'summaryformat' => $details->summaryformat,
            'timemodified' => time(),
        ]);

        $this->discard($course_id, $section_id);
        rebuild_course_cache($course_id, true);

        return true;
    }

    public function discard(int $course_id, int $section_id): void
    {
        get_file_storage()->delete_area_files(
            \core\context\course::instance($course_id)->id,
            self::COMPONENT,
            self::FILEAREA,
            $section_id
        );
    }

    public function has_snapshot(int $course_id, int $section_id): bool
    {
        return get_file_storage()->file_exists(
            \core\context\course::instance($course_id)->id,
            self::COMPONENT,
            self::FILEAREA,
            $section_id,
            '/',
            self::SNAPSHOT_FILENAME
        );
    }

    private function snapshot_record(int $context_id, int $section_id, string $filepath, string $filename): array
    {
        return [
            'contextid' => $context_id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $section_id,
            'filepath' => $filepath,
            'filename' => $filename,
        ];
    }
}
