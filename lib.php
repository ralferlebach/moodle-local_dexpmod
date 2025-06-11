<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local plugin "DexpMod" - lib
 * *
 * @package     local_dexpmod
 * @copyright   2022 Alexander Dominicus, Bochum University of Applied Science <alexander.dominicus@hs-bochum.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends the navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $navref
 *
 * @return void
 */
function local_dexpmod_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $navref): void {
    global $PAGE;

    // Only add this settings item on non-site course pages.
    if (!$PAGE->course or $PAGE->course->id == 1) {
        return;
    }
    // Only let users with the appropriate capability see this settings item.
    if (!has_capability('local/dexpmod:movedates', context_course::instance($PAGE->course->id))) {
        return;
    }

    if ($settingnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        $strfoo = get_string('course_link', 'local_dexpmod');
        $url = new moodle_url('/local/dexpmod/index.php', ['id' => $PAGE->course->id]);
        $foonode = navigation_node::create(
            $strfoo,
            $url,
            navigation_node::NODETYPE_LEAF,
            'addbe',
            'addbe',
            new pix_icon('i/scheduled', 'addbe')
        );
        if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
            $foonode->make_active();
        }
        $settingnode->add_node($foonode);
    }
}

/**
 * Returns the activities with completion set in current course.
 *
 * @param int       $courseID   ID of the course
 * @param ?int      $config     The block instance configuration
 * @param ?string   $forceOrder An override for the course order setting
 *
 * @return array Activities with completion settings in the course
 */
function local_dexpmod_get_activities(int $courseID, ?int $config = null, ?string $forceOrder = null): array {
    $modInfo = get_fast_modinfo($courseID, -1);
    $sections = $modInfo->get_sections();
    $activities = [];
    foreach ($modInfo->instances as $module => $instances) {
        $moduleName = get_string('pluginname', $module);
        foreach ($instances as $index => $cm) {
            if (
                $cm->completion != COMPLETION_TRACKING_NONE && (
                    $config == null || (
                        !isset($config->activitiesincluded) || (
                            $config->activitiesincluded != 'selectedactivities' ||
                            !empty($config->selectactivities) &&
                            in_array($module . '-' . $cm->instance, $config->selectactivities))))
            ) {
                $activities[] = [
                    'type' => $module,
                    'modulename' => $moduleName,
                    'id' => $cm->id,
                    'instance' => $cm->instance,
                    'name' => format_string($cm->name),
                    'expected' => $cm->completionexpected,
                    'section' => $cm->sectionnum,
                    'position' => array_search($cm->id, $sections[$cm->sectionnum]),
                    'url' => method_exists($cm->url, 'out') ? $cm->url->out() : '',
                    'context' => $cm->context,
                    'icon' => $cm->get_icon_url(),
                    'available' => $cm->available,
                    'visible' => $cm->visible,
                ];
            }
        }
    }

    // Sort by first value in each element, which is time due.
    // if ($forceorder == 'orderbycourse' || ($config && $config->orderby == 'orderbycourse')) {
    //     usort($activities, 'block_completion_progress_compare_events');
    // } else {
    //     usort($activities, 'block_completion_progress_compare_times');
    // }

    return $activities;
}

/**
 * Moves the chosen activities and returns a list of these activities.
 *
 * @param int       $courseid
 * @param array     $data  form data
 *
 * @return array table of activities
 */
function move_activities(int $courseid, array $data): array {
    global $DB;

    //Get all activities in the course.
    $activities = local_dexpmod_get_activities($courseid, null, 'orderbycourse');
    $addduration = $data->timeduration;
    $sqlparams = ['course' => $courseid];
    $expectedarray = $DB->get_records('course_modules', $sqlparams);
    $table = new html_table();
    $table->head = ['Aktivität', 'Abschlusstermin'];

    foreach ($activities as $index => $activity) {
        if ($activity['expected'] > 0) {
            //Activities with expected completion

            // Check if all activities should be moved.
            if ($data->config_activitiesincluded == 'allactivites') {
                // Move all activities contained in the course.
                if ($data->datedependence) {
                    $recordparams = ['id' => $activity['id']];
                    $expectedold = $DB->get_record('course_modules', $recordparams, $fields = '*');

                    if ($data->date_min <= $expectedold->completionexpected && $expectedold->completionexpected <= $data->date_max) {
                        $newdate = $expectedold->completionexpected + $addduration;
                        $updateparams = ['id' => $activity['id'], 'completionexpected' => $newdate];
                        $DB->update_record('course_modules', $updateparams);

                        // To ensure a valid date read expexted completion from DB.
                        $replaceddate = $DB->get_record('course_modules', $recordparams, $fields = '*');

                        $table->data[] = [$activity['name'], userdate($replaceddate->completionexpected)];
                    }
                } else {
                    $recordparams = ['id' => $activity['id']];
                    $expectedold = $DB->get_record('course_modules', $recordparams, $fields = '*');
                    $newdate = $expectedold->completionexpected + $addduration;
                    $updateparams = ['id' => $activity['id'], 'completionexpected' => $newdate];
                    $DB->update_record('course_modules', $updateparams);
                    // To ensure a valid date read expextec completion from DB
                    $replaceddate = $DB->get_record('course_modules', $recordparams, $fields = '*');
                    //  echo $OUTPUT->heading($activity['name']." -> ". userdate( $replaced_date->completionexpected),5);
                    $table->data[] = [$activity['name'], userdate($replaceddate->completionexpected)];
                }
            } else {
                // All Activities chosen by the user
                if (in_array($activity['id'], $data->selectactivities)) {
                    $recordparams = ['id' => $activity['id']];
                    $expectedold = $DB->get_record('course_modules', $recordparams, $fields = '*');
                    $newdate = $expectedold->completionexpected + $addduration;
                    $updateparams = ['id' => $activity['id'], 'completionexpected' => $newdate];
                    $DB->update_record('course_modules', $updateparams);
                    // To ensure a valid date read expextec completion from DB
                    $replaceddate = $DB->get_record('course_modules', $recordparams, $fields = '*');

                    // echo $OUTPUT->heading($activity['name']." -> ". userdate( $replaced_date->completionexpected),5);
                    $table->data[] = [$activity['name'], userdate($replaceddate->completionexpected)];
                }
            }
        }
    }

    return $table;
}

/**
 * Returns the activities with completion set in current course.
 *
 * @param int courseID      Course ID
 * @param ?int dateMin       Minimum date
 * @param ?int dateMax       Maximum date
 *
 * @return array            Table of activities
 */
function list_all_activities(int $courseID, ?int $dateMin = null, ?int $dateMax = bnull): array {
    global $DB;
    //Standard values without submitting the form

    $activities = local_dexpmod_get_activities($courseID, null, 'orderbycourse');
    $table = new html_table();
    $table->head = [get_string('section', 'local_dexpmod'), get_string('activity', 'local_dexpmod'), get_string('duedate', 'local_dexpmod')];
    // echo $OUTPUT->heading('Kursinformationen: '.get_course($courseID)->fullname  ,2);
    $sqlParams = ['course' => $courseID];
    foreach ($activities as $index => $activity) {
        // // Show only visible acitivities!
        if ($activity['visible'] == '0') {
            continue;
        }
        if ($activity['expected'] > 0) {
            if ($dateMin)   {
                if($activity['expected'] >= $dateMin && $activity['expected']<= $dateMax)   {
                    $recordParams = ['id' => $activity['id']];
                     $dateExpected = $DB->get_record('course_modules', $recordParams, $fields = '*');
                    // echo $OUTPUT->heading("&nbsp"."&#8226". $activity['name'].": ".userdate($date_expected->completionexpected) ,5);
                    $table->data[] = [$activity['section'], $activity['name'], date('d.m.y-H:i', $dateExpected->completionexpected)];
                }
            else {
                continue;
            }
            }
            else{
            $recordParams = ['id' => $activity['id']];
            $dateExpected = $DB->get_record('course_modules', $recordParams, $fields = '*');
            // echo $OUTPUT->heading("&nbsp"."&#8226". $activity['name'].": ".userdate($date_expected->completionexpected) ,5);
            $table->data[] = [$activity['section'], $activity['name'], date('d.m.y-H:i', $dateExpected->completionexpected)];
            }

        }
    }

    return $table;
}
