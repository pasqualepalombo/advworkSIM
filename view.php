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
 * Prints a particular instance of advwork
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_advwork
 * @copyright  2009 David Mudrak <david.mudrak@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');

$id         = optional_param('id', 0, PARAM_INT); // course_module ID, or
$w          = optional_param('w', 0, PARAM_INT);  // advwork instance ID
$editmode   = optional_param('editmode', null, PARAM_BOOL);
$page       = optional_param('page', 0, PARAM_INT);
$perpage    = optional_param('perpage', null, PARAM_INT);
$sortby     = optional_param('sortby', 'lastname', PARAM_ALPHA);
$sorthow    = optional_param('sorthow', 'ASC', PARAM_ALPHA);
$eval       = optional_param('eval', null, PARAM_PLUGIN);

if ($id) {
    $cm             = get_coursemodule_from_id('advwork', $id, 0, false, MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $advworkrecord = $DB->get_record('advwork', array('id' => $cm->instance), '*', MUST_EXIST);
    $wid=$id;
} else {
    $advworkrecord = $DB->get_record('advwork', array('id' => $w), '*', MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $advworkrecord->course), '*', MUST_EXIST);
    $cm             = get_coursemodule_from_instance('advwork', $advworkrecord->id, $course->id, false, MUST_EXIST);
    $wid=$w;
}

require_login($course, true, $cm);
require_capability('mod/advwork:view', $PAGE->context);

$advwork = new advwork($advworkrecord, $cm, $course);
$courseid = $advwork->course->id;
$courseteachersid = $advwork->get_course_teachers($courseid);
$iscourseteacher = in_array($USER->id, $courseteachersid);

$advwork->setCapabilitiesDB();
$advwork->setDomainValueDB();



$PAGE->set_url($advwork->view_url());

// Mark viewed.
$advwork->set_module_viewed();

// If the phase is to be switched, do it asap. This just has to happen after triggering
// the event so that the scheduled allocator had a chance to allocate submissions.
if ($advwork->phase == advwork::PHASE_SUBMISSION and $advwork->phaseswitchassessment
        and $advwork->submissionend > 0 and $advwork->submissionend < time()) {
    $advwork->switch_phase(advwork::PHASE_ASSESSMENT);
    // Disable the automatic switching now so that it is not executed again by accident
    // if the teacher changes the phase back to the submission one.
    $DB->set_field('advwork', 'phaseswitchassessment', 0, array('id' => $advwork->id));
    $advwork->phaseswitchassessment = 0;
}

if (!is_null($editmode) && $PAGE->user_allowed_editing()) {
    $USER->editing = $editmode;
}

$userplan = new advwork_user_plan($advwork, $USER->id);

foreach ($userplan->phases as $phase) {
    if ($phase->active) {
        $currentphasetitle = $phase->title;
    }
}

$PAGE->set_title($advwork->name . " (" . $currentphasetitle . ")");
$PAGE->set_heading($course->fullname);

if ($perpage and $perpage > 0 and $perpage <= 1000) {
    require_sesskey();
    set_user_preference('advwork_perpage', $perpage);
    redirect($PAGE->url);
}

if ($eval) {
    require_sesskey();
    require_capability('mod/advwork:overridegrades', $advwork->context);
    $advwork->set_grading_evaluation_method($eval);
    redirect($PAGE->url);
}

$output = $PAGE->get_renderer('mod_advwork');


/**
 * Displays the student model relative position to other peers in the class
 *
 * @param $DB               Moodle Database API object
 * @param $advwork         advwork instance
 * @param $courseid         id of the current course
 * @param $userid           id of the student to display the model for
 * @return void
 */
function display_student_model_relative_position($DB, $advwork, $courseid, $userid) {
    global $isgeneralstudentmodelpage;
    global $capabilities;
    global $studentsmodels;
    global $studentsmodelsprevioussession;
    global $currentuserid;

    $isgeneralstudentmodelpage = false;
    $capabilities = $advwork->get_capabilities();

    $studentsenrolledtocourse = $advwork->get_students_enrolled_to_course($DB, $courseid);
    $studentsmodels = [];
    $studentsmodelsprevioussession = [];
    foreach ($studentsenrolledtocourse as $student) {
        $studentmodelresponse = $advwork->get_student_model($courseid, $advwork->id, $student->userid, false);
        $studentmodel = new advwork_student_model();
        $studentmodel->userid = $student->userid;
        $studentmodel->entries = $studentmodelresponse;
        if(!empty($studentmodelresponse)) {
            $studentsmodels[] = $studentmodel;
        }

        $studentmodelprevioussession = $advwork->get_student_model_before_specified_session($courseid, $advwork->id, $student->userid, 0);
        if(!empty($studentmodelprevioussession)) {
            $studentsmodelsprevioussession[] = $studentmodelprevioussession;
        }
    }

    $currentuserid = $userid;

    include('studentmodeling/jquery.php');
    include('studentmodeling/studentrelativeposition.php');
}

/**
 * Display the model of the student for the current session
 *
 * @param $output                   object used to render data
 * @param $advwork                 advwork instance
 * @param $courseid                 id of the current course
 * @param $userid                   id of the student to display the model for
 * @throws
 * @return void
 */
function display_student_model_current_session($output, $advwork, $courseid, $userid) {
    $studentmodelresponse = $advwork->get_student_model($courseid, $advwork->id, $userid, false);
    $studentmodel = new advwork_student_model();
    $studentmodel->entries = $studentmodelresponse;

    if (!empty($studentmodel)) {
        print_collapsible_region_start('', 'advwork-viewlet-yourstudentmodel', get_string('yourstudentmodel', 'advwork'));
        echo $output->box_start('generalbox grades-yourstudentmodel');
        echo $output->render($studentmodel);
        echo $output->box_end();
        print_collapsible_region_end();
    }
}

/**
 * Display the average submission grade for a session
 *
 * @param $output                   object used to render data
 * @param $advwork                 advwork instance
 * @param $courseid                 id of the course
 * @return void
 */
function display_average_submission_grade_session($output, $advwork, $courseid) {
    $averagesubmissiongradesession = $advwork->compute_average_submission_grade_session($advwork, $courseid);

    $objecttorender = new advwork_average_submission_grade_session();
    $objecttorender->averagesubmissiongrade = $averagesubmissiongradesession;

    if(!empty($averagesubmissiongradesession) && !is_nan($averagesubmissiongradesession)) {
        print_collapsible_region_start('', 'advwork-viewlet-submissionsgradesaverage', get_string('averagesubmissiongradeforsession', 'advwork'));
        echo $output->box_start('generalbox grades-submissionsgradesaverage');
        echo $output->render($objecttorender);
        echo $output->box_end();
        print_collapsible_region_end();
    }
}

/**
 * Display the standard deviation of the submissions' grades for a session
 *
 * @param $output                   object used to render data
 * @param $advwork                 advwork instance
 * @param $courseid                 id of the course
 * @return void
 */
function display_standard_deviation_submission_grades_session($output, $advwork, $courseid) {
    $submissionsgradessession = $advwork->get_submissions_grades_for_session($advwork, $courseid);
    $standarddeviationsubmissionsgrades = $advwork->compute_standard_deviation($submissionsgradessession);

    $objecttorender = new advwork_standard_deviation_submissions_grades_session();
    $objecttorender->standarddeviation = $standarddeviationsubmissionsgrades;

    if(!empty($submissionsgradessession)  && !is_nan($standarddeviationsubmissionsgrades)) {
        print_collapsible_region_start('', 'advwork-viewlet-submissionsgradesstandarddeviation', get_string('standarddeviationsubmissionsgradessession', 'advwork'));
        echo $output->box_start('generalbox grades-submissionsgradesstandarddeviation');
        echo $output->render($objecttorender);
        echo $output->box_end();
        print_collapsible_region_end();
    }
}

/**
 * Displays a link to the general student model page for the student
 *
 * @param $output                       Object used to render
 * @param $advwork                     advwork instance
 * @param $id                           Id
 */
function display_general_student_model_link_for_student($output, $advwork, $id) {
    // print a region with a link
    echo $output->box_start('generalbox generalstudentmodel');

    $generalstudentmodelurl = new advwork_general_student_model_url();
    $generalstudentmodelurl->url = $advwork->general_student_model_url($id);
    $generalstudentmodelurl->title = get_string("viewgeneralstudentmodel", "advwork");
    echo $output->render($generalstudentmodelurl);

    echo $output->box_end();
}

/**
 * Displays a link to the general student model page for the teacher
 *
 * @param $output                       Object used to render
 * @param $advwork                     advwork instance
 * @param $id                           Id
 */
function display_general_student_model_link_for_teacher($output, $advwork, $id) {
    echo $output->box_start('generalbox generalstudentmodelteacher');

    $generalstudentmodelurl = new advwork_general_student_model_url();
    $generalstudentmodelurl->url = $advwork->general_student_model_url_teacher($id);
    $generalstudentmodelurl->title = get_string("viewgeneralstudentmodel", "advwork");
    echo $output->render($generalstudentmodelurl);

    echo $output->box_end();
}


/**
 * Display the model of the student in general
 *
 * @param $output               object used to render data
 * @param $advwork             advwork instance
 * @param $courseid             id of the current course
 * @param $userid               id of the student to display the model for
 * @return void
 */
/*
function display_student_model_in_general($output, $advwork, $courseid, $userid) {
    $generalstudentmodel = $advwork->get_general_student_model($courseid, $userid);
    if(!empty($generalstudentmodel)) {
        print_collapsible_region_start('', 'advwork-viewlet-yourgeneralstudentmodel', get_string('yourgeneralstudentmodel', 'advwork'));
        echo $output->box_start('generalbox grades-yourstudentmodel');
        echo $output->render($generalstudentmodel);
        echo $output->box_end();
        print_collapsible_region_end();
    }
}*/

/**
 * Displays a section with the most appropriate answer to be graded next by the teacher
 *
 * @param $output                   Object used to render
 * @param $advwork                 advwork instance
 * @param $courseid                 Id of the course
 */
function display_most_appropriate_answer_to_grade_next($output, $advwork, $courseid) {
        global $PAGE;
        $groupid = groups_get_activity_group($advwork->cm, true);

        $nextbestsubmissiontograde = $advwork->get_next_best_submission_to_grade($advwork, $courseid, $groupid);
        if(empty($nextbestsubmissiontograde)) {
            print_collapsible_region_start('', 'advwork-viewlet-mostappropriateanswertograde', get_string('mostappropriateanswertogradenext', 'advwork'));
            echo "No submissions available to grade.";
            print_collapsible_region_end();
            return;
        }

        $submission                     = new stdclass();
        $submission->id                 = $nextbestsubmissiontograde->id;
        $submission->title              = $nextbestsubmissiontograde->title;
        $submission->timecreated        = $nextbestsubmissiontograde->timecreated;
        $submission->timemodified       = $nextbestsubmissiontograde->timemodified;

        $authorinfo = $advwork->get_user_information($nextbestsubmissiontograde->authorid);
        $userpicturefields = explode(',', user_picture::fields());
        foreach ($userpicturefields as $userpicturefield) {
            $prefixedusernamefield = 'author' . $userpicturefield;
            $submission->$prefixedusernamefield = $authorinfo->$prefixedusernamefield;
        }

        $class = ' notgraded';
        $submission->status = 'notgraded';
        $buttontext = get_string('assess', 'advwork');

        print_collapsible_region_start('', 'advwork-viewlet-mostappropriateanswertograde', get_string('mostappropriateanswertogradenext', 'advwork'));
        $shownames = has_capability('mod/advwork:viewauthornames', $PAGE->context);
        echo $output->box_start('generalbox assessment-summary' . $class);
        echo $output->render($advwork->prepare_submission_summary($submission, $shownames));
        echo $output->box_end();
        print_collapsible_region_end();
}

/// Output starts here

echo $output->header();

echo $output->heading_with_help(format_string($advwork->name), 'userplan', 'advwork');
echo $output->heading(format_string($currentphasetitle), 3, null, 'mod_advwork-userplanheading');

//SIMULATION CLASS LINK
$link = $advwork->createclasssimulation_url();
echo "<a href='$link'><button class='btn btn-primary'>SIMULATION CLASS</button></a>";

echo $output->render($userplan);

switch ($advwork->phase) {
case advwork::PHASE_SETUP:
    if (trim($advwork->intro)) {
        print_collapsible_region_start('', 'advwork-viewlet-intro', get_string('introduction', 'advwork'));
        echo $output->box(format_module_intro('advwork', $advwork, $advwork->cm->id), 'generalbox');
        print_collapsible_region_end();
    }
    if ($advwork->useexamples and has_capability('mod/advwork:manageexamples', $PAGE->context)) {
        print_collapsible_region_start('', 'advwork-viewlet-allexamples', get_string('examplesubmissions', 'advwork'));
        echo $output->box_start('generalbox examples');
        if ($advwork->grading_strategy_instance()->form_ready()) {
            if (! $examples = $advwork->get_examples_for_manager()) {
                echo $output->container(get_string('noexamples', 'advwork'), 'noexamples');
            }
            foreach ($examples as $example) {
                $summary = $advwork->prepare_example_summary($example);
                $summary->editable = true;
                echo $output->render($summary);
            }
            $aurl = new moodle_url($advwork->exsubmission_url(0), array('edit' => 'on'));
            echo $output->single_button($aurl, get_string('exampleadd', 'advwork'), 'get');
        } else {
            echo $output->container(get_string('noexamplesformready', 'advwork'));
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }
    break;
case advwork::PHASE_SUBMISSION:
    if (trim($advwork->instructauthors)) {
        $instructions = file_rewrite_pluginfile_urls($advwork->instructauthors, 'pluginfile.php', $PAGE->context->id,
            'mod_advwork', 'instructauthors', null, advwork::instruction_editors_options($PAGE->context));
        print_collapsible_region_start('', 'advwork-viewlet-instructauthors', get_string('instructauthors', 'advwork'));
        echo $output->box(format_text($instructions, $advwork->instructauthorsformat, array('overflowdiv'=>true)), array('generalbox', 'instructions'));
        print_collapsible_region_end();
    }

    // does the user have to assess examples before submitting their own work?
    $examplesmust = ($advwork->useexamples and $advwork->examplesmode == advwork::EXAMPLES_BEFORE_SUBMISSION);

    // is the assessment of example submissions considered finished?
    $examplesdone = has_capability('mod/advwork:manageexamples', $advwork->context);
    if ($advwork->assessing_examples_allowed()
            and has_capability('mod/advwork:submit', $advwork->context)
                    and ! has_capability('mod/advwork:manageexamples', $advwork->context)) {
        $examples = $userplan->get_examples();
        $total = count($examples);
        $left = 0;
        // make sure the current user has all examples allocated
        foreach ($examples as $exampleid => $example) {
            if (is_null($example->assessmentid)) {
                $examples[$exampleid]->assessmentid = $advwork->add_allocation($example, $USER->id, 0);
            }
            if (is_null($example->grade)) {
                $left++;
            }
        }
        if ($left > 0 and $advwork->examplesmode != advwork::EXAMPLES_VOLUNTARY) {
            $examplesdone = false;
        } else {
            $examplesdone = true;
        }
        print_collapsible_region_start('', 'advwork-viewlet-examples', get_string('exampleassessments', 'advwork'), false, $examplesdone);
        echo $output->box_start('generalbox exampleassessments');
        if ($total == 0) {
            echo $output->heading(get_string('noexamples', 'advwork'), 3);
        } else {
            foreach ($examples as $example) {
                $summary = $advwork->prepare_example_summary($example);
                echo $output->render($summary);
            }
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }

    if (has_capability('mod/advwork:submit', $PAGE->context) and (!$examplesmust or $examplesdone)) {
        print_collapsible_region_start('', 'advwork-viewlet-ownsubmission', get_string('yoursubmission', 'advwork'));
        echo $output->box_start('generalbox ownsubmission');
        if ($submission = $advwork->get_submission_by_author($USER->id)) {
            echo $output->render($advwork->prepare_submission_summary($submission, true));
            if ($advwork->modifying_submission_allowed($USER->id)) {
                $btnurl = new moodle_url($advwork->submission_url(), array('edit' => 'on'));
                $btntxt = get_string('editsubmission', 'advwork');
            }
        } else {
            echo $output->container(get_string('noyoursubmission', 'advwork'));
            if ($advwork->creating_submission_allowed($USER->id)) {
                $btnurl = new moodle_url($advwork->submission_url(), array('edit' => 'on'));
                $btntxt = get_string('createsubmission', 'advwork');
            }
        }
        if (!empty($btnurl)) {
            echo $output->single_button($btnurl, $btntxt, 'get');
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }

    if (has_capability('mod/advwork:viewallsubmissions', $PAGE->context)) {
        $groupmode = groups_get_activity_groupmode($advwork->cm);
        $groupid = groups_get_activity_group($advwork->cm, true);

        if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $advwork->context)) {
            $allowedgroups = groups_get_activity_allowed_groups($advwork->cm);
            if (empty($allowedgroups)) {
                echo $output->container(get_string('groupnoallowed', 'mod_advwork'), 'groupwidget error');
                break;
            }
            if (! in_array($groupid, array_keys($allowedgroups))) {
                echo $output->container(get_string('groupnotamember', 'core_group'), 'groupwidget error');
                break;
            }
        }

        print_collapsible_region_start('', 'advwork-viewlet-allsubmissions', get_string('submissionsreport', 'advwork'));

        $perpage = get_user_preferences('advwork_perpage', 10);
        $data = $advwork->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
        if ($data) {
            $countparticipants = $advwork->count_participants();
            $countsubmissions = $advwork->count_submissions(array_keys($data->grades), $groupid);
            $a = new stdClass();
            $a->submitted = $countsubmissions;
            $a->notsubmitted = $data->totalcount - $countsubmissions;

            echo html_writer::tag('div', get_string('submittednotsubmitted', 'advwork', $a));

            echo $output->container(groups_print_activity_menu($advwork->cm, $PAGE->url, true), 'groupwidget');

            // Prepare the paging bar.
            $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
            $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

            // Populate the display options for the submissions report.
            $reportopts                     = new stdclass();
            $reportopts->showauthornames         = has_capability('mod/advwork:viewauthornames', $advwork->context);
            $reportopts->showreviewernames       = has_capability('mod/advwork:viewreviewernames', $advwork->context);
            $reportopts->sortby                  = $sortby;
            $reportopts->sorthow                 = $sorthow;
            $reportopts->showsubmissiongrade     = false;
            $reportopts->showgradinggrade        = false;
            $reportopts->showsinglesessiongrades = false;
            $reportopts->showcumulatedgrades     = false;
            $reportopts->advworkphase           = $advwork->phase;

            echo $output->render($pagingbar);
            echo $output->render(new advwork_grading_report($data, $reportopts));
            echo $output->render($pagingbar);
            echo $output->perpage_selector($perpage);
        } else {
            echo html_writer::tag('div', get_string('nothingfound', 'advwork'), array('class' => 'nothingfound'));
        }
        print_collapsible_region_end();
    }
    break;

case advwork::PHASE_ASSESSMENT:

    $ownsubmissionexists = null;
    if (has_capability('mod/advwork:submit', $PAGE->context)) {
        if ($ownsubmission = $advwork->get_submission_by_author($USER->id)) {
            print_collapsible_region_start('', 'advwork-viewlet-ownsubmission', get_string('yoursubmission', 'advwork'), false, true);
            echo $output->box_start('generalbox ownsubmission');
            echo $output->render($advwork->prepare_submission_summary($ownsubmission, true));
            $ownsubmissionexists = true;
        } else {
            print_collapsible_region_start('', 'advwork-viewlet-ownsubmission', get_string('yoursubmission', 'advwork'));
            echo $output->box_start('generalbox ownsubmission');
            echo $output->container(get_string('noyoursubmission', 'advwork'));
            $ownsubmissionexists = false;
            if ($advwork->creating_submission_allowed($USER->id)) {
                $btnurl = new moodle_url($advwork->submission_url(), array('edit' => 'on'));
                $btntxt = get_string('createsubmission', 'advwork');
            }
        }
        if (!empty($btnurl)) {
            echo $output->single_button($btnurl, $btntxt, 'get');
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }

    if (has_capability('mod/advwork:viewallassessments', $PAGE->context)) {
        # Start : Giuseppe Bruno
        # old: $perpage = get_user_preferences('advwork_perpage', 10);
        $perpage = get_user_preferences('advwork_perpage', 500);
        # End : Giuseppe Bruno
        $groupid = groups_get_activity_group($advwork->cm, true);
        $data = $advwork->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
        # Start : Giuseppe Bruno
        echo    "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.getElementById('apriNuovaScheda').addEventListener('click', function() {
                            window.open('markshistory.php', '_blank');
                        });
                    });
                </script>
                <button id='apriNuovaScheda' class='btn btn-warning' style='width:100%'>History</button>";
        # End : Giuseppe Bruno
        if ($data) {
            $showauthornames    = has_capability('mod/advwork:viewauthornames', $advwork->context);
            $showreviewernames  = has_capability('mod/advwork:viewreviewernames', $advwork->context);

            // prepare paging bar
            $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
            $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

            // grading report display options
            $reportopts                          = new stdclass();
            $reportopts->showauthornames         = $showauthornames;
            $reportopts->showreviewernames       = $showreviewernames;
            $reportopts->sortby                  = $sortby;
            $reportopts->sorthow                 = $sorthow;
            $reportopts->showsubmissiongrade     = false;
            $reportopts->showgradinggrade        = false;
            $reportopts->showsinglesessiongrades = false;
            $reportopts->showcumulatedgrades     = false;
            $reportopts->advworkphase           = $advwork->phase;

            print_collapsible_region_start('', 'advwork-viewlet-gradereport', get_string('gradesreport', 'advwork'));
            echo $output->box_start('generalbox gradesreport');
            echo $output->container(groups_print_activity_menu($advwork->cm, $PAGE->url, true), 'groupwidget');
            echo $output->render($pagingbar);
            echo $output->render(new advwork_grading_report($data, $reportopts));
            echo $output->render($pagingbar);
            echo $output->perpage_selector($perpage);
            echo $output->box_end();
            print_collapsible_region_end();
        }
    }
    if (trim($advwork->instructreviewers)) {
        $instructions = file_rewrite_pluginfile_urls($advwork->instructreviewers, 'pluginfile.php', $PAGE->context->id,
            'mod_advwork', 'instructreviewers', null, advwork::instruction_editors_options($PAGE->context));
        print_collapsible_region_start('', 'advwork-viewlet-instructreviewers', get_string('instructreviewers', 'advwork'));
        echo $output->box(format_text($instructions, $advwork->instructreviewersformat, array('overflowdiv'=>true)), array('generalbox', 'instructions'));
        print_collapsible_region_end();
    }

    // does the user have to assess examples before assessing other's work?
    $examplesmust = ($advwork->useexamples and $advwork->examplesmode == advwork::EXAMPLES_BEFORE_ASSESSMENT);

    // is the assessment of example submissions considered finished?
    $examplesdone = has_capability('mod/advwork:manageexamples', $advwork->context);

    // can the examples be assessed?
    $examplesavailable = true;

    if (!$examplesdone and $examplesmust and ($ownsubmissionexists === false)) {
        print_collapsible_region_start('', 'advwork-viewlet-examplesfail', get_string('exampleassessments', 'advwork'));
        echo $output->box(get_string('exampleneedsubmission', 'advwork'));
        print_collapsible_region_end();
        $examplesavailable = false;
    }

    if ($advwork->assessing_examples_allowed()
            and has_capability('mod/advwork:submit', $advwork->context)
                and ! has_capability('mod/advwork:manageexamples', $advwork->context)
                    and $examplesavailable) {
        $examples = $userplan->get_examples();
        $total = count($examples);
        $left = 0;
        // make sure the current user has all examples allocated
        foreach ($examples as $exampleid => $example) {
            if (is_null($example->assessmentid)) {
                $examples[$exampleid]->assessmentid = $advwork->add_allocation($example, $USER->id, 0);
            }
            if (is_null($example->grade)) {
                $left++;
            }
        }
        if ($left > 0 and $advwork->examplesmode != advwork::EXAMPLES_VOLUNTARY) {
            $examplesdone = false;
        } else {
            $examplesdone = true;
        }
        print_collapsible_region_start('', 'advwork-viewlet-examples', get_string('exampleassessments', 'advwork'), false, $examplesdone);
        echo $output->box_start('generalbox exampleassessments');
        if ($total == 0) {
            echo $output->heading(get_string('noexamples', 'advwork'), 3);
        } else {
            foreach ($examples as $example) {
                $summary = $advwork->prepare_example_summary($example);
                echo $output->render($summary);
            }
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }
    if (!$examplesmust or $examplesdone) {
        print_collapsible_region_start('', 'advwork-viewlet-assignedassessments', get_string('assignedassessments', 'advwork'));
        if (! $assessments = $advwork->get_assessments_by_reviewer($USER->id)) {
            echo $output->box_start('generalbox assessment-none');
            echo $output->notification(get_string('assignedassessmentsnone', 'advwork'));
            echo $output->box_end();
        } else {
            $shownames = has_capability('mod/advwork:viewauthornames', $PAGE->context);
            foreach ($assessments as $assessment) {
                $submission                     = new stdClass();
                $submission->id                 = $assessment->submissionid;
                $submission->title              = $assessment->submissiontitle;
                $submission->timecreated        = $assessment->submissioncreated;
                $submission->timemodified       = $assessment->submissionmodified;
                $userpicturefields = explode(',', user_picture::fields());
                foreach ($userpicturefields as $userpicturefield) {
                    $prefixedusernamefield = 'author' . $userpicturefield;
                    $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
                }

                // transform the submission object into renderable component
                $submission = $advwork->prepare_submission_summary($submission, $shownames);

                if (is_null($assessment->grade)) {
                    $submission->status = 'notgraded';
                    $class = ' notgraded';
                    $buttontext = get_string('assess', 'advwork');
                } else {
                    $submission->status = 'graded';
                    $class = ' graded';
                    $buttontext = get_string('reassess', 'advwork');
                }

                echo $output->box_start('generalbox assessment-summary' . $class);
                echo $output->render($submission);
                $aurl = $advwork->assess_url($assessment->id);
                echo $output->single_button($aurl, $buttontext, 'get');
                echo $output->box_end();
            }
        }
        print_collapsible_region_end();
    }
    break;
case advwork::PHASE_EVALUATION:
    if (has_capability('mod/advwork:viewallassessments', $PAGE->context)) {
        $perpage = get_user_preferences('advwork_perpage', 10);
        $groupid = groups_get_activity_group($advwork->cm, true);
        $data = $advwork->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
        $data->capabilitiesquartiles = $advwork->compute_capabilities_quartiles($courseid, $advwork);


        if ($data) {
            $showauthornames    = has_capability('mod/advwork:viewauthornames', $advwork->context);
            $showreviewernames  = has_capability('mod/advwork:viewreviewernames', $advwork->context);

            if (has_capability('mod/advwork:overridegrades', $PAGE->context)) {
                // Print a drop-down selector to change the current evaluation method.
                $selector = new single_select($PAGE->url, 'eval', advwork::available_evaluators_list(),
                    $advwork->evaluation, false, 'evaluationmethodchooser');
                $selector->set_label(get_string('evaluationmethod', 'mod_advwork'));
                $selector->set_help_icon('evaluationmethod', 'mod_advwork');
                $selector->method = 'post';
                echo $output->render($selector);
                // load the grading evaluator
                $evaluator = $advwork->grading_evaluation_instance();
                $groupidParam= optional_param('$groupidParam', $groupid, PARAM_INT);

                $allowedgroups = groups_get_all_groups($cm->course, 0, $cm->groupingid); // any group in grouping

                $form = $evaluator->get_settings_form(new moodle_url($advwork->aggregate_url(),
                        compact('sortby', 'sorthow', 'page', 'groupidParam')));
                $form->display();
            }

            // prepare paging bar
            $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
            $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

            // grading report display options
            $reportopts                          = new stdclass();
            $reportopts->showauthornames         = $showauthornames;
            $reportopts->showreviewernames       = $showreviewernames;
            $reportopts->sortby                  = $sortby;
            $reportopts->sorthow                 = $sorthow;
            $reportopts->showsubmissiongrade     = true;
            $reportopts->showgradinggrade        = true;
            $reportopts->showsinglesessiongrades = true;
            $reportopts->showcumulatedgrades     = true;
            $reportopts->advworkphase           = $advwork->phase;

            # Start : Giuseppe Bruno
            //set the csv directory path in a session variable to be used in the 'markshistory.php' page
            $csvhistorydirectory = 'csvfiles/history/'. $course->fullname ."/". $advwork->id ."-". $advwork->name;
            $_SESSION['csvhistorydirectory']=$csvhistorydirectory;
            //if saveandclose or saveandcontinue (buttons) were pressed and was set the submissionid from the assessment page
            //or is pressed "Re-calulate grades" button
            if(((isset($_SESSION['saveandclosefromassessment']) || isset($_SESSION['saveandcontinuefromassessment'])) && 
            ($_SESSION['saveandclosefromassessment'] || $_SESSION['saveandcontinuefromassessment']) &&
            (isset($_SESSION['submissionidfromassessment']) && $_SESSION['submissionidfromassessment'])) ||
            (isset($_SESSION['calculate_grades_button']) && $_SESSION['calculate_grades_button'])){
                $aspectsandweights=$DB->get_records_sql("SELECT id,grade,weight FROM `mdl_advworkform_acc_mod` WHERE advworkid=$advwork->id"); // query per avere voto massimo e peso relativo all'advwork
                $i=0;
                $ok=true;
                $maxnumreview=0;
                //open the $data->grades variable (the table is composed from that)
                foreach($data->grades as $riga){
                    //saving all the datas i need to save in the csv files in $vals (userid,firstname,lastname)
                    $vals[$i]['userid']=$riga->userid;
                    $vals[$i]['firstname']=$riga->firstname;
                    $vals[$i]['lastname']=$riga->lastname;
                    $count=1;
                    foreach($riga->reviewedby as $rigarevby){
                        //if it's not a teacher grade, save if on the csv
                        if(!in_array($rigarevby->userid, $courseteachersid)){  
                            //saving all the datas i need to save in the csv files in $vals (gradereceived x, gradereceived x aspect y,useridreceived x)
                            $vals[$i]['gradesreceived']['gradereceived'.$count][0]=$rigarevby->grade; 
                            $vals[$i]['gradesreceived']['useridreceived'.$count][0]=$rigarevby->userid;
                            $gradeaspects=$DB->get_records_sql("SELECT mdl_advwork_grades.id,mdl_advwork_grades.grade FROM mdl_advwork_grades JOIN mdl_advwork_assessments ON mdl_advwork_grades.assessmentid=mdl_advwork_assessments.id WHERE mdl_advwork_assessments.reviewerid=$rigarevby->userid and mdl_advwork_assessments.submissionid=$riga->submissionid");
                            $countaspects=1;
                            foreach($gradeaspects as $igrade){
                                $vals[$i]['gradesreceived']['gradereceived'.$count]['aspect'.$countaspects]=$igrade->grade;
                                $countaspects++;
                            }
                                                
                            $count++;
                        }
                        elseif($rigarevby->grade==$riga->submissiongradesinglesession){
                            //saving all the datas i need to save in the csv files in $vals (submission grade teacherid, submission grade aspect y)
                            $vals[$i]['sumbissiongradeaspects']['id']=$rigarevby->userid;
                            $gradeaspects=$DB->get_records_sql("SELECT mdl_advwork_grades.id,mdl_advwork_grades.grade FROM `mdl_advwork_assessments`join mdl_advwork_grades on mdl_advwork_grades.assessmentid=mdl_advwork_assessments.id where mdl_advwork_assessments.reviewerid=$rigarevby->userid and mdl_advwork_assessments.submissionid=$rigarevby->submissionid");
                            $countaspects=1;
                            foreach($gradeaspects as $igrade){
                                $vals[$i]['sumbissiongradeaspects']['aspect'.$countaspects]=$igrade->grade;
                                $countaspects++;
                            }
                        }
                        
                    }
                    
                    $numaspectsandweights=$countaspects-1;

                    $count=1;
                    foreach($riga->reviewerof as $rigarevof){
                        //saving all the datas i need to save in the csv files in $vals (grade given x,userid given x, grade given x aspect y)
                        $vals[$i]['gradesgiven']['gradegiven'.$count][0]=$rigarevof->grade; 
                        $vals[$i]['gradesgiven']['useridgiven'.$count][0]=$rigarevof->userid;  
                        $gradeaspects=$DB->get_records_sql("SELECT mdl_advwork_grades.id,mdl_advwork_grades.grade FROM `mdl_advwork_grades` join mdl_advwork_assessments on mdl_advwork_assessments.id=mdl_advwork_grades.assessmentid join mdl_advwork_submissions on mdl_advwork_assessments.submissionid=mdl_advwork_submissions.id where mdl_advwork_assessments.reviewerid=$riga->userid and mdl_advwork_submissions.authorid=$rigarevof->userid and mdl_advwork_submissions.advworkid=$advwork->id");
                        $countaspects=1;
                        foreach($gradeaspects as $igrade){
                            $vals[$i]['gradesgiven']['gradegiven'.$count]['aspect'.$countaspects]=$igrade->grade;
                            $countaspects++;
                        }                      
                        $count++;
                    }

                    if($maxnumreview < ($count-1)){
                        $maxnumreview=$count-1;
                    }

                    $count=1;
                    //saving all the datas i need to save in the csv files in $vals (max grade aspect y, weight % aspect y)
                    foreach($aspectsandweights as $obj){
                        $vals[$i]['maxgradeaspect'.$count]= $obj->grade;
                        $vals[$i]['weightaspect'.$count]= ($obj->weight)/10;
                        
                        $count++;
                    }
                    
                    
                    //if we could not connect to the bayesian network, we could not have all the datas in the table, so to not write the csv $ok is set to false
                    if(!$riga->submissiongradesinglesession){
                        $ok=false;
                        break;
                    }else{
                        //saving all the datas i need to save in the csv files in $vals (submission grade, competence (single session), assessment capability (single session))
                        $vals[$i]['submissiongradesinglesession']=$riga->submissiongradesinglesession;
                        $vals[$i]['competencesinglesession']=$riga->competencesinglesession;
                        $vals[$i]['assessmentcapabilitysinglesession']=$riga->assessmentcapabilitysinglesession;
                        //saving all the datas i need to save in the csv files in $vals (competence (cumulated), assessment capability (cumulated))
                        $vals[$i]['competencecumulated']=$riga->competencecumulated;
                        $vals[$i]['assessmentcapabilitycumulated']=$riga->assessmentcapabilitycumulated;
                        $i++;
                    }
                }
                
                // if $ok is false, we had problems so won't be written the csv file
                if(!$ok){
                    echo '<script>console.log("Possibile errore nella connessione al BNS Server");</script>';
                }else{
                    //if the directory of the csvfiles doesn't exist yet, it's created if possible
                    if (!is_dir($csvhistorydirectory)) {
                        if (!mkdir($csvhistorydirectory, 0777, true)) {
                            die('Errore nella creazione della csvhistorydirectory: ' . $csvhistorydirectory);
                        }
                    }
                    
                    //get all the csv files in the csvhistorydirectory
                    $csvfiles = glob($csvhistorydirectory . '/*.csv');
                    $numcsvhistoryfiles=count($csvfiles);

                    if(!isset($_SESSION['csvhistorynum'])){
                        if ($numcsvhistoryfiles == 0) {
                            $_SESSION['csvhistorynum']=0;
                        }else{
                            $csvmaxold=0;
                            //find the max number the csv files starts with (10-7 14-5 2-45 -> 14) to have a non continuos ascending order
                            foreach ($csvfiles as $csvfile) {
                                // get only the name of the file
                                $filenamecsv = pathinfo(basename($csvfile), PATHINFO_FILENAME);
                                // divide the string at the "-" 
                                $csvstringarray = explode("-", $filenamecsv);
                                if(((int)$csvstringarray[0]) > $csvmaxold){
                                    $csvmaxold=(int)$csvstringarray[0];
                                }
                            }
                            $_SESSION['csvhistorynum']=$csvmaxold+1;
                        }
                    }
                    $csvnum=$_SESSION['csvhistorynum'];
                    //if is not pressed the "Re-calulate grades" button write the last submissionid, evaluated by the teacher, as the second part of the file name
                    if(!$_SESSION['calculate_grades_button']){
                        $filename = $csvnum.'-'.$_SESSION['submissionidfromassessment'];
                    }else{
                        //if is pressed the "Re-calulate grades" button, name the csv file with 0-0
                        $filename = '0-0';
                    }
                    
                    // if we have the submissionid, save it, otherwise save 0
                    if(isset($_SESSION['submissionidfromassessment']) && $_SESSION['submissionidfromassessment'] ){
                        $submissionidfromassessment=$_SESSION['submissionidfromassessment'];
                    }else{
                        $submissionidfromassessment=0;
                    }
                    // go through all the csv files
                    foreach ($csvfiles as $csvfile) {
                        // get the filename without path
                        $filenamecsv = pathinfo(basename($csvfile), PATHINFO_FILENAME);
                        // separate the string on the "-"
                        $csvstringarray = explode("-", $filenamecsv);
                        //if it's found a csv file that has the same submissionid we want to save, delete the old one and stop searching
                        if((int)$csvstringarray[1] == (int)$submissionidfromassessment){
                            unlink($csvfile);
                            break;
                        }                
                    }
                    // create the csv file and open it on write mode
                    $handle = fopen($csvhistorydirectory.'/'.$filename.'.csv', 'w');
                    //set the start of the csv file's header
                    $header = ['Userid', 'Firstname', 'Lastname'];

                    // add grade received x, userid received x and grade received x aspect y to the header
                    for ($i = 1; $i <= $maxnumreview; $i++) {
                        $header[] = "Grade received ".$i;
                        for($j=1; $j<=$numaspectsandweights; $j++){
                            $header[] = "Grade received ".$i." Aspect ".$j;
                        }
                        $header[] = "Userid received ".$i;
                    }

                    // add grade given x, userid given x and grade given x aspect y to the header
                    for ($i = 1; $i <= $maxnumreview; $i++) {
                        $header[] = "Grade given ".$i;
                        for($j=1; $j<=$numaspectsandweights; $j++){
                            $header[] = "Grade given ".$i." Aspect ".$j;
                        }
                        $header[] = "Userid given ".$i;
                    }
                    // add max grade aspect x and weight % aspect y to the header
                    for ($i = 1; $i <= $numaspectsandweights; $i++) {
                        $header[] = "Max Grade Aspect ".$i;
                        $header[] = "Weight % Aspect ".$i;                    
                    }
                    // add submission grade aspect y and submission grade teacherid to the header
                    for ($i = 1; $i <= $numaspectsandweights; $i++) {
                        $header[] = "Submission grade Aspect ".$i;                   
                    }
                    $header[] = "Submission grade teacherid"; 
                    
                    // add the last ones to the header
                    $header = array_merge($header, [
                        'Submission grade', 
                        'Competence (single session)', 
                        'Assessment capability (single session)', 
                        'Competence (cumulated)', 
                        'Assessment capability (cumulated)'
                    ]);
                    
                    // set the header as the first row of $dati
                    $dati[0] = $header;
                    $i=1;
                    foreach($vals as $val){
                        //add sequentially the $vals's rows to $dati
                        $dati[$i] = [];

                        $dati[$i][] = $val['userid'];
                        $dati[$i][] = $val['firstname'];
                        $dati[$i][] = $val['lastname'];

                        // add exactly $maxnumreview number of columns 'received' and the grades to every aspect for every one of them
                        for($count=1; $count <= $maxnumreview; $count++){
                            if (!$val['gradesreceived']['gradereceived'.$count][0]) {
                                $dati[$i][] = "NULL";
                            } else {
                                $dati[$i][] = $val['gradesreceived']['gradereceived'.$count][0];
                            }

                            for($j=1; $j<=$numaspectsandweights; $j++){
                                $dati[$i][]=(int)$val['gradesreceived']['gradereceived'.$count]['aspect'.$j];
                            }  

                            if (!$val['gradesreceived']['useridreceived'.$count][0]) {
                                $dati[$i][] = "NULL";
                            } else {
                                $dati[$i][] = $val['gradesreceived']['useridreceived'.$count][0];
                            }


                        }
                    
                        // same thing (as upper) for givens
                        for($count=1; $count <= $maxnumreview; $count++){
                            if (!$val['gradesgiven']['gradegiven'.$count][0]) {
                                $dati[$i][] = "NULL";
                            } else {
                                $dati[$i][] = $val['gradesgiven']['gradegiven'.$count][0];
                            }
                            
                            for($j=1; $j<=$numaspectsandweights; $j++){
                                $dati[$i][]=(int)$val['gradesgiven']['gradegiven'.$count]['aspect'.$j];
                            }

                            if (!$val['gradesgiven']['useridgiven'.$count][0]) {
                                $dati[$i][] = "NULL";
                            } else {
                                $dati[$i][] = $val['gradesgiven']['useridgiven'.$count][0];
                            }
                        }

                        //add the max grade aspects and the weights
                        for($count=1; $count <= $numaspectsandweights; $count++){
                            $dati[$i][] = $val['maxgradeaspect'.$count];
                            $dati[$i][] = $val['weightaspect'.$count];                       
                        }
                        // add the last ones
                        for ($j = 1; $j <= $numaspectsandweights; $j++) {
                            $dati[$i][] = (int)$val['sumbissiongradeaspects']['aspect'.$j];             
                        }
                        $dati[$i][] = $val['sumbissiongradeaspects']['id']; 
                        $dati[$i][] = $val['submissiongradesinglesession'];
                        $dati[$i][] = $val['competencesinglesession'];
                        $dati[$i][] = $val['assessmentcapabilitysinglesession'];
                    
                        if (!$val['competencecumulated']) {
                            $dati[$i][] = "NULL";
                        } else {
                            $dati[$i][] = $val['competencecumulated'];
                        }
                    
                        if (!$val['assessmentcapabilitycumulated']) {
                            $dati[$i][] = "NULL";
                        } else {
                            $dati[$i][] = $val['assessmentcapabilitycumulated'];
                        }
                    
                        $i++;
                    }
                    
                    // write $dati in the csv opened before
                    foreach ($dati as $riga) {
                        fputcsv($handle, $riga);
                    }

                    // close the file
                    fclose($handle);
                    $_SESSION['csvhistorynum']+=1;
                }
                // unset some variables used only to write this csv
                unset($_SESSION['saveandclosefromassessment']);
                unset($_SESSION['submissionidfromassessment']);
                unset($_SESSION['calculate_grades_button']);
                // if it was pressed the button saveandcontinuefromassessment, we need to go back to the page we were in 
                if(isset($_SESSION['saveandcontinuefromassessment']) && isset($_SESSION['assessurl'])){
                    unset($_SESSION['saveandcontinuefromassessment']);
                    redirect($_SESSION['assessurl']);
                }
            }
        # End : Giuseppe Bruno

            print_collapsible_region_start('', 'advwork-viewlet-gradereport', get_string('gradesreport', 'advwork'));
            echo $output->box_start('generalbox gradesreport');
            echo $output->container(groups_print_activity_menu($advwork->cm, $PAGE->url, true), 'groupwidget');
            echo $output->render($pagingbar);
            echo $output->render(new advwork_grading_report($data, $reportopts));
            echo $output->render($pagingbar);
            echo $output->perpage_selector($perpage);
            echo $output->box_end();
            print_collapsible_region_end();
        }
    }

    if(isloggedin() && $iscourseteacher) {
        display_average_submission_grade_session($output, $advwork, $courseid);
        display_standard_deviation_submission_grades_session($output, $advwork, $courseid);
    }

    // general student model for student
    if (isloggedin() && !$iscourseteacher) {
        display_general_student_model_link_for_student($output, $advwork, $id);
    }

    // general student model for teacher
    if (isloggedin() && $iscourseteacher) {
        display_general_student_model_link_for_teacher($output, $advwork, $id);
    }

    if (has_capability('mod/advwork:overridegrades', $advwork->context)) {
        print_collapsible_region_start('', 'advwork-viewlet-cleargrades', get_string('toolbox', 'advwork'), false, true);
        echo $output->box_start('generalbox toolbox');

        // Clear aggregated grades
        $url = new moodle_url($advwork->toolbox_url('clearaggregatedgrades'));
        $btn = new single_button($url, get_string('clearaggregatedgrades', 'advwork'), 'post');
        $btn->add_confirm_action(get_string('clearaggregatedgradesconfirm', 'advwork'));
        echo $output->container_start('toolboxaction');
        echo $output->render($btn);
        echo $output->help_icon('clearaggregatedgrades', 'advwork');
        echo $output->container_end();
        // Clear assessments
        $url = new moodle_url($advwork->toolbox_url('clearassessments'));
        $btn = new single_button($url, get_string('clearassessments', 'advwork'), 'post');
        $btn->add_confirm_action(get_string('clearassessmentsconfirm', 'advwork'));
        echo $output->container_start('toolboxaction');
        echo $output->render($btn);
        echo $output->help_icon('clearassessments', 'advwork');

        echo $OUTPUT->pix_icon('i/risk_dataloss', get_string('riskdatalossshort', 'admin'));
        echo $output->container_end();

        echo $output->box_end();
        print_collapsible_region_end();
    }
    if (has_capability('mod/advwork:submit', $PAGE->context)) {
        print_collapsible_region_start('', 'advwork-viewlet-ownsubmission', get_string('yoursubmission', 'advwork'));
        echo $output->box_start('generalbox ownsubmission');
        if ($submission = $advwork->get_submission_by_author($USER->id)) {
            echo $output->render($advwork->prepare_submission_summary($submission, true));
        } else {
            echo $output->container(get_string('noyoursubmission', 'advwork'));
        }
        echo $output->box_end();
        print_collapsible_region_end();
    }

    if (isloggedin() && $iscourseteacher) {
        display_most_appropriate_answer_to_grade_next($output, $advwork, $courseid);
    }

    $groupid = groups_get_activity_group($advwork->cm, true);
    $nextbestsubmissiontograde = $advwork->get_next_best_submission_to_grade($advwork, $courseid, $groupid);
    if(!empty($nextbestsubmissiontograde)) {
        $nextbestsubmissiontogradeid = $nextbestsubmissiontograde->id;
    } else {
        $nextbestsubmissiontogradeid = null;
    }
    if ($assessments = $advwork->get_assessments_by_reviewer($USER->id, $nextbestsubmissiontogradeid)) {
        print_collapsible_region_start('', 'advwork-viewlet-assignedassessments', get_string('assignedassessments', 'advwork'));
        $shownames = has_capability('mod/advwork:viewauthornames', $PAGE->context);
        foreach ($assessments as $assessment) {
            $submission                     = new stdclass();
            $submission->id                 = $assessment->submissionid;
            $submission->title              = $assessment->submissiontitle;
            $submission->timecreated        = $assessment->submissioncreated;
            $submission->timemodified       = $assessment->submissionmodified;
            $userpicturefields = explode(',', user_picture::fields());
            foreach ($userpicturefields as $userpicturefield) {
                $prefixedusernamefield = 'author' . $userpicturefield;
                $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
            }

            if (is_null($assessment->grade)) {
                $class = ' notgraded';
                $submission->status = 'notgraded';
                $buttontext = get_string('assess', 'advwork');
            } else {
                $class = ' graded';
                $submission->status = 'graded';
                $buttontext = get_string('reassess', 'advwork');
            }
            echo $output->box_start('generalbox assessment-summary' . $class);
            echo $output->render($advwork->prepare_submission_summary($submission, $shownames));
            echo $output->box_end();
        }
        print_collapsible_region_end();
    }
    break;
case advwork::PHASE_CLOSED:
	if (version_compare(phpversion(), '7.1', '>=')) {
		ini_set( 'serialize_precision', -1 );
	}

    if (trim($advwork->conclusion)) {
        $conclusion = file_rewrite_pluginfile_urls($advwork->conclusion, 'pluginfile.php', $advwork->context->id,
            'mod_advwork', 'conclusion', null, advwork::instruction_editors_options($advwork->context));
        print_collapsible_region_start('', 'advwork-viewlet-conclusion', get_string('conclusion', 'advwork'));
        echo $output->box(format_text($conclusion, $advwork->conclusionformat, array('overflowdiv'=>true)), array('generalbox', 'conclusion'));
        print_collapsible_region_end();
    }

    if (!has_capability('mod/advwork:viewallassessments', $PAGE->context)) {
        display_student_model_current_session($output, $advwork, $courseid, $USER->id);
    } else {
        $finalgrades = $advwork->get_gradebook_grades($USER->id);

        if (!empty($finalgrades)) {
            print_collapsible_region_start('', 'advwork-viewlet-yourgrades', get_string('yourgrades', 'advwork'));
            echo $output->box_start('generalbox grades-yourgrades');
            echo $output->render($finalgrades);
            echo $output->box_end();
            print_collapsible_region_end();
        }
    }

    $overallgrades = $advwork->get_overall_grades($DB, $courseid, $USER->id);
    /*
    if(!empty($overallgrades)) {
        print_collapsible_region_start('', 'advwork-viewlet-youroverallgrades', get_string('youroverallgrades', 'advwork'));
        echo $output->box_start('generalbox grades-youroverallgrades');
        echo $output->render($overallgrades);
        echo $output->box_end();
        print_collapsible_region_end();
    }*/

    /*
    $reliabilitymetrics = $overallgrades->reliability_metrics;
    if(!empty($reliabilitymetrics) && !$iscourseteacher) {
        print_collapsible_region_start('', 'advwork-viewlet-yourreliabilitymetrics', get_string('yourreliabilitymetrics', 'advwork'));
        echo $output->box_start('generalbox grades-yourreliabilitymetrics');
        echo $output->render($reliabilitymetrics);
        echo $output->box_end();
        print_collapsible_region_end();
    }*/

    // display student model section
    if (!has_capability('mod/advwork:viewallassessments', $PAGE->context)) {
        //display_student_model_current_session($output, $advwork, $courseid, $USER->id);
        //display_student_model_in_general($output, $advwork, $courseid, $USER->id);

        display_student_model_relative_position($DB, $advwork, $courseid, $USER->id);
    }

    if (has_capability('mod/advwork:viewallassessments', $PAGE->context)) {
        $perpage = get_user_preferences('advwork_perpage', 10);
        $groupid = groups_get_activity_group($advwork->cm, true);

        $data = $advwork->prepare_grading_report_data($USER->id, $groupid, $page, $perpage, $sortby, $sorthow);
        $data->capabilitiesquartiles = $advwork->compute_capabilities_quartiles($courseid, $advwork);

        if ($data) {
            $showauthornames    = has_capability('mod/advwork:viewauthornames', $advwork->context);
            $showreviewernames  = has_capability('mod/advwork:viewreviewernames', $advwork->context);

            // prepare paging bar
            $baseurl = new moodle_url($PAGE->url, array('sortby' => $sortby, 'sorthow' => $sorthow));
            $pagingbar = new paging_bar($data->totalcount, $page, $perpage, $baseurl, 'page');

            // grading report display options
            $reportopts                          = new stdclass();
            $reportopts->showauthornames         = $showauthornames;
            $reportopts->showreviewernames       = $showreviewernames;
            $reportopts->sortby                  = $sortby;
            $reportopts->sorthow                 = $sorthow;
            $reportopts->showsubmissiongrade     = true;
            $reportopts->showgradinggrade        = true;
            $reportopts->showsinglesessiongrades = true;
            $reportopts->showcumulatedgrades     = true;
            $reportopts->advworkphase           = $advwork->phase;

            print_collapsible_region_start('', 'advwork-viewlet-gradereport', get_string('gradesreport', 'advwork'));
            echo $output->box_start('generalbox gradesreport');
            echo $output->container(groups_print_activity_menu($advwork->cm, $PAGE->url, true), 'groupwidget');
            echo $output->render($pagingbar);
            echo $output->render(new advwork_grading_report($data, $reportopts));
            echo $output->render($pagingbar);
            echo $output->perpage_selector($perpage);
            echo $output->box_end();
            print_collapsible_region_end();
        }
    }

    if(isloggedin() && $iscourseteacher) {
        display_average_submission_grade_session($output, $advwork, $courseid);
        display_standard_deviation_submission_grades_session($output, $advwork, $courseid);
    }

    // general student model for student
    if (isloggedin() && !$iscourseteacher) {
        display_general_student_model_link_for_student($output, $advwork, $id);
    }

    // general student model for teacher
    if (isloggedin() && $iscourseteacher) {
        display_general_student_model_link_for_teacher($output, $advwork, $id);
    }

    if (has_capability('mod/advwork:submit', $PAGE->context)) {
        print_collapsible_region_start('', 'advwork-viewlet-ownsubmission', get_string('yoursubmission', 'advwork'));
        echo $output->box_start('generalbox ownsubmission');
        if ($submission = $advwork->get_submission_by_author($USER->id)) {
            echo $output->render($advwork->prepare_submission_summary($submission, true));
        } else {
            echo $output->container(get_string('noyoursubmission', 'advwork'));
        }

        echo $output->box_end();

        if (!empty($submission->gradeoverby) and strlen(trim($submission->feedbackauthor)) > 0) {
            echo $output->render(new advwork_feedback_author($submission));
        }

        print_collapsible_region_end();
    }
    if (has_capability('mod/advwork:viewpublishedsubmissions', $advwork->context)) {
        $shownames = has_capability('mod/advwork:viewauthorpublished', $advwork->context);
        if ($submissions = $advwork->get_published_submissions()) {
            print_collapsible_region_start('', 'advwork-viewlet-publicsubmissions', get_string('publishedsubmissions', 'advwork'));
            foreach ($submissions as $submission) {
                echo $output->box_start('generalbox submission-summary');
                echo $output->render($advwork->prepare_submission_summary($submission, $shownames));
                echo $output->box_end();
            }
            print_collapsible_region_end();
        }
    }

    if (isloggedin() && $iscourseteacher) {
        display_most_appropriate_answer_to_grade_next($output, $advwork, $courseid);
    }

    $groupid = groups_get_activity_group($advwork->cm, true);
    $nextbestsubmissiontograde = $advwork->get_next_best_submission_to_grade($advwork, $courseid, $groupid);
    if(!empty($nextbestsubmissiontograde)) {
        $nextbestsubmissiontogradeid = $nextbestsubmissiontograde->id;
    } else {
        $nextbestsubmissiontogradeid = null;
    }
    if ($assessments = $advwork->get_assessments_by_reviewer($USER->id, $nextbestsubmissiontogradeid)) {
        print_collapsible_region_start('', 'advwork-viewlet-assignedassessments', get_string('assignedassessments', 'advwork'));
        $shownames = has_capability('mod/advwork:viewauthornames', $PAGE->context);
        foreach ($assessments as $assessment) {
            $submission                     = new stdclass();
            $submission->id                 = $assessment->submissionid;
            $submission->title              = $assessment->submissiontitle;
            $submission->timecreated        = $assessment->submissioncreated;
            $submission->timemodified       = $assessment->submissionmodified;
            $userpicturefields = explode(',', user_picture::fields());
            foreach ($userpicturefields as $userpicturefield) {
                $prefixedusernamefield = 'author' . $userpicturefield;
                $submission->$prefixedusernamefield = $assessment->$prefixedusernamefield;
            }

            if (is_null($assessment->grade)) {
                $class = ' notgraded';
                $submission->status = 'notgraded';
                $buttontext = get_string('assess', 'advwork');
            } else {
                $class = ' graded';
                $submission->status = 'graded';
                $buttontext = get_string('reassess', 'advwork');
            }
            echo $output->box_start('generalbox assessment-summary' . $class);
            echo $output->render($advwork->prepare_submission_summary($submission, $shownames));
            echo $output->box_end();

            if (strlen(trim($assessment->feedbackreviewer)) > 0) {
                echo $output->render(new advwork_feedback_reviewer($assessment));
            }
        }
        print_collapsible_region_end();
        print_collapsible_region_start('', 'advwork-viewlet-publicsubmissions', 'Graphics Reports');
        $linkreport="./report/index.php?cid=$courseid&wid=$wid";
        ?><br>
        <a href="<?php echo $linkreport; ?>"><h4><?php echo get_string('seeallreport', 'advwork'); ?></h4></a>
        <?php
        print_collapsible_region_end();
    }
    break;
default:
}
$PAGE->requires->js_call_amd('mod_advwork/advworkview', 'init');
echo $output->footer();


