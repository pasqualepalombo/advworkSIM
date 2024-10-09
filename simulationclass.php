<?php
#echo "<script type='text/javascript'>alert('$message');</script>";
# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Creazione della classe simulata
 *
 * @package    mod_advwork
 * @copyright  2024 Pasquale Palombo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

#Advwork Library
require(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
require_once(__DIR__.'/allocation/random/lib.php');

#Moodle Library for creating/handling users
require_once($CFG->libdir . '/moodlelib.php'); # Include il user_create_user
require_once($CFG->dirroot . '/user/lib.php'); # Include la libreria utenti di Moodle.
require_once($CFG->libdir . '/datalib.php'); # Include le funzioni del database di Moodle.
require_once($CFG->libdir . '/enrollib.php'); // Include le funzioni di iscrizione.

#Moodle Library for managing groups and grouping
require_once($CFG->dirroot . '/group/lib.php');

use core_user; # Usa il namespace corretto per la classe core_user.

$id         = required_param('id', PARAM_INT); #id dell'activity, non del corso
$w          = optional_param('w', 0, PARAM_INT);  # forse scarto
$editmode   = optional_param('editmode', null, PARAM_BOOL);
$page       = optional_param('page', 0, PARAM_INT);
$perpage    = optional_param('perpage', null, PARAM_INT);
$sortby     = optional_param('sortby', 'lastname', PARAM_ALPHA);
$sorthow    = optional_param('sorthow', 'ASC', PARAM_ALPHA);
$eval       = optional_param('eval', null, PARAM_PLUGIN);

# Cotrollo se il parametro ID è stato passato.
if ($id) {
    $cm             = get_coursemodule_from_id('advwork', $id, 0, false, MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $advworkrecord = $DB->get_record('advwork', array('id' => $cm->instance), '*', MUST_EXIST);
    $wid=$id; # forse scarto
} else {
    $advworkrecord = $DB->get_record('advwork', array('id' => $w), '*', MUST_EXIST);
    $course         = $DB->get_record('course', array('id' => $advworkrecord->course), '*', MUST_EXIST);
    $cm             = get_coursemodule_from_instance('advwork', $advworkrecord->id, $course->id, false, MUST_EXIST);
    $wid=$w; # forse scarto
}

require_login($course, true, $cm);
require_capability('mod/advwork:simulationclass', $PAGE->context);


$advwork = new advwork($advworkrecord, $cm, $course);
$courseid = $advwork->course->id;
$courseteachersid = $advwork->get_course_teachers($courseid);
$iscourseteacher = in_array($USER->id, $courseteachersid);
$advwork->setCapabilitiesDB();
$advwork->setDomainValueDB();
$PAGE->set_url($advwork->view_url());
$advwork->set_module_viewed();
$output = $PAGE->get_renderer('mod_advwork');

$PAGE->set_title('Simulation Class');

#SIM MESSAGES
$message_create = '';
$message_enroll = '';
$message_submission = '';
$message_groups = '';
$message_allocation = '';
$message_allocation = '';

#SIM FORM HANDLER
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['create_students_btn'])) {
        $students_number_to_create = intval($_POST["students_number_to_create"]);
        create_simulation_students($students_number_to_create);
    }
    elseif (isset($_POST['enroll_students_btn'])) {
        $students_number_to_enroll= intval($_POST["students_number_to_enroll"]);
        enroll_simulated_users($students_number_to_enroll, $courseid);
    }
    elseif (isset($_POST['create_submissions_btn'])) {
        create_submissions($courseid, $advwork->id);
    }
    elseif (isset($_POST['create_groups_btn'])) {
        create_groups_for_course($courseid);
    }
    elseif (isset($_POST['create_grouping_btn'])) {
        create_grouping_with_all_groups($courseid, 'SIM Grouping');
    }
    elseif (isset($_POST['create_allocation_btn'])) {
        create_allocation_among_groups();
    }
}

#SIM FUNCTIONS
function read_how_many_sim_students_already_exists($prefix = 'sim_student', $return_array = true){
    global $DB;

    $sql = "SELECT * FROM {user} WHERE username LIKE :prefix AND deleted = 0"; 
    $params = ['prefix' => $prefix . '%'];

    # Se si vuole restituire l'array con i risultati.
    if ($return_array) {
        $students = $DB->get_records_sql($sql, $params);
        return $students;
    } else {
        # Se si vuole solo il numero degli utenti che corrispondono.
        $sql_count = "SELECT COUNT(*) FROM {user} WHERE username LIKE :prefix AND deleted = 0";
        $count = $DB->count_records_sql($sql_count, $params);
        return $count;
    }
}

function display_function_message($message){
    if (!empty($message)) {
        echo '<div class="alert alert-warning" role="alert"> ' . $message . '</div>';
    }
}

function create_simulation_students($students_number_to_create){
    global $message_create;
    $students_number_already_created = read_how_many_sim_students_already_exists('sim_student', false);
    
    if ($students_number_already_created >= $students_number_to_create) {
        
        $message_create = 'Si hanno a disposizione abbastanza studenti';
    }
    elseif ($students_number_already_created < $students_number_to_create) {
        $remaining_students = $students_number_to_create - $students_number_already_created;
        for ($x = $students_number_already_created + 1; $x <= $students_number_to_create; $x++) {

            $userdata = [
                'username' => 'sim_student_' . $x,
                'password' => '123',
                'firstname' => 'SIM',
                'lastname' => 'STUDENT',
                'email' => 'student' . $x . '@sim.com'
            ];
            
            # Chiama la funzione per creare il nuovo utente.
            try {
                $new_user = create_custom_user($userdata);
                $message_create = "Utenti creato con successo.";
            } catch (Exception $e) {
                $message_create = "Errore nella creazione dell'utente: " . $e->getMessage();
            }

        }
    }
}

function create_custom_user($userdata) {
    # Controlla che i dati necessari siano presenti.
    if (empty($userdata['username']) || empty($userdata['password']) || empty($userdata['email'])) {
        throw new Exception('Dati mancanti: username, password o email non sono presenti.');
    }

    $user = new stdClass();
    $user->username = $userdata['username'];
    $user->password = hash_internal_user_password($userdata['password']);
    $user->firstname = $userdata['firstname'];
    $user->lastname = $userdata['lastname'];
    $user->email = $userdata['email'];
    $user->confirmed = 1;
    $user->mnethostid = $GLOBALS['CFG']->mnet_localhost_id;
    $user->auth = 'manual';
    $user->timecreated = time();
    $user->timemodified = time();

    # user_create_user è la funziona di moodle
    $new_user = user_create_user($user);

    return $new_user;
}

function get_students_in_course($courseid, $return_array = true) {
    global $DB;
    
    $sql = "
        SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email
        FROM {user} u
        JOIN {user_enrolments} ue ON ue.userid = u.id
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {role_assignments} ra ON ra.userid = u.id
        JOIN {context} ctx ON ra.contextid = ctx.id
        JOIN {role} r ON ra.roleid = r.id
        WHERE e.courseid = :courseid
        AND r.shortname = 'student'";  // Ruolo di studente.
    
    $params = ['courseid' => $courseid];

    # Se si vuole restituire l'array di studenti.
    if ($return_array) {
        $students = $DB->get_records_sql($sql, $params);
        return $students;
    } else {
        # Se si vuole solo il numero degli studenti.
        $sql_count = "
            SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ra.contextid = ctx.id
            JOIN {role} r ON ra.roleid = r.id
            WHERE e.courseid = :courseid
            AND r.shortname = 'student'";
        
        $count = $DB->count_records_sql($sql_count, $params);
        return $count;
    }
}

function enroll_simulated_users($students_number_to_enroll, $courseid){    
    global $message_enroll;
    global $DB;

    $students_number_already_created = read_how_many_sim_students_already_exists('sim_student', false);

    if ($students_number_to_enroll <= $students_number_already_created) {
        
        $students = read_how_many_sim_students_already_exists('sim_student', true);
        $students_to_enroll = array_slice($students, 0, $students_number_to_enroll);
        
        if (empty($courseid) || empty($students_to_enroll)) {
            $message_enroll = 'Il corso o gli studenti non sono stati definiti correttamente.';
        }
        
        $enrol = $DB->get_record('enrol', array('courseid' => $courseid, 'enrol' => 'manual'), '*', MUST_EXIST);

        $enrol_manual = enrol_get_plugin('manual');

        if ($enrol && $enrol_manual) {
            foreach ($students_to_enroll as $student) {
                $student_id = $student->id; 
                $enrol_manual->enrol_user($enrol, $student_id, 5); // 5 è l'ID del ruolo di 'student' di mdl_role.
            }
            $message_enroll = "Studenti iscritti con successo al corso";
        } else {
            $message_enroll = 'Non è stato possibile trovare il metodo di iscrizione manuale per il corso.';
        } 
    }
    else {
        $message_enroll = 'Non ci sono abbastanza studenti per iscriverli tutti';
    }
}

function get_submissions($advwork_id, $return_array = true) {
    global $DB;

    $sql = "
        SELECT *
        FROM mdl_advwork_submissions
        WHERE advworkid = :advworkid";

    $params = ['advworkid' => $advwork_id];

    // Se si vuole restituire l'array di submission
    if ($return_array) {
        $submissions = $DB->get_records_sql($sql, $params);
        return $submissions;
    } else {
        // Se si vuole solo il numero delle submission
        $sql_count = "
            SELECT COUNT(*)
            FROM mdl_advwork_submissions
            WHERE advworkid = :advworkid";

        $count = $DB->count_records_sql($sql_count, $params);
        return $count;
    }
}

function get_submission_authors_id($advwork_id) {
    global $DB;

    $sql = "
        SELECT authorid
        FROM mdl_advwork_submissions
        WHERE advworkid = :advworkid";

    $params = ['advworkid' => $advwork_id];
    $submissions = $DB->get_records_sql($sql, $params);
    return $submissions;
}

function create_submissions($courseid, $advwork_id) {
    global $DB;
    global $message_submission;
    # si creano solo le submission degli studenti che non ne hanno una
    $enrolled_students = get_students_in_course($courseid, true);
    $submission_authors_already_exited = get_submission_authors_id($advwork_id);
    $latest_students = array_filter($enrolled_students, function($student) use ($submission_authors_already_exited) {
        foreach ($submission_authors_already_exited as $author) {
            if ($student->id === $author->authorid) {
                return false;
            }
        }
        return true;
    });
    
    $title = 'Submission_title_by_SM';
    $content = '<p dir="ltr" style="text-align: left;">Sumission_content_by_SM</p>';
    
    foreach ($latest_students as $student){
        $authorid = $student->id;
        $data = new stdClass();
        $data->advworkid = $advwork_id;
        $data->authorid = $student->id;
        $data->timecreated = time();
        $data->timemodified = time();
        $data->title = $title;
        $data->content = $content;
        $data->feedbackauthorformat = 1;
        $data->contentformat = 1; # 1 per testo)
        
        $DB->insert_record('advwork_submissions', $data);
    }
    $message_submission = 'All submissions created. Now allocate with groups and grouping';
}

function count_groups_in_course($courseid) {
    global $DB;
    $sql = "SELECT COUNT(id) 
            FROM {groups} 
            WHERE courseid = :courseid";
    $params = ['courseid' => $courseid];
    $count = $DB->count_records_sql($sql, $params);
    return $count;
}

function create_groups_for_course($courseid) {
    global $DB;
    global $message_groups;

    $students = get_students_in_course($courseid, true);
    $students_array = array_values($students);

    $total_students = count($students_array);
    $group_size = 4;
    $num_groups = ceil($total_students / $group_size);

    if($num_groups == count_groups_in_course($courseid)){
        $message_groups = 'Gruppi già presenti, non ne sono stati creati altri';
        return;
    }

    for ($i = 0; $i < $num_groups; $i++) {
        $group_name = "SIM Group " . ($i + 1);
        $group_data = new stdClass();
        $group_data->courseid = $courseid;
        $group_data->name = $group_name;
        $group_data->description = "Gruppo creato in maniera automatica.";
        $groupid = groups_create_group($group_data);

        for ($j = 0; $j < $group_size; $j++) {
            $student_index = ($i * $group_size) + $j;
            if ($student_index < $total_students) {
                $student_id = $students_array[$student_index]->id;
                groups_add_member($groupid, $student_id);
            }
        }
    }
    #create_grouping_with_all_groups($courseid, 'SIM Grouping');
    $message_submission = "Gruppi creati con successo per il corso con ID: $courseid";
}

function get_course_grouping_name($courseid) {
    global $DB;

    $course = $DB->get_record('course', ['id' => $courseid], 'defaultgroupingid');
    
    if (empty($course->defaultgroupingid)) {
        return "nessuno";
    }

    $grouping = $DB->get_record('groupings', ['id' => $course->defaultgroupingid], 'name');
    
    return $grouping ? $grouping->name : "nessuno";
}

function set_groups_course_setting($courseid, $groupingid) {
    global $DB;
    $data = new stdClass();
    $data->id = $courseid;
    $data->groupmode = SEPARATEGROUPS;
    $data->groupmodeforce = 1;
    $data->defaultgroupingid = $groupingid;
    $DB->update_record('course', $data);
}

function create_grouping_with_all_groups($courseid, $groupingname) {
    global $CFG, $DB;
    
    $groupingdata = new stdClass();
    $groupingdata->courseid = $courseid;
    $groupingdata->name = $groupingname;
    $groupingdata->description = 'Grouping with all groups';
    $groupingdata->descriptionformat = FORMAT_HTML;
    $groupingdata->timecreated = time();
    $groupingdata->timemodified = time();

    $groupingid = groups_create_grouping($groupingdata);

    $groups = $DB->get_records('groups', array('courseid' => $courseid));

    foreach ($groups as $group) {
        groups_assign_grouping($groupingid, $group->id);
    }

    set_groups_course_setting($courseid, $groupingid);

    return $groupingid;
}

function check_the_allocation($courseid, $advwork_id) {
    global $DB;

    $enrolled_students = get_students_in_course($courseid, true);
    $submission_ids = array_map(function($submission) {
        return $submission->authorid;
    }, get_submissions($advwork_id, true));

    $result = [];
    $submissionids_placeholder = implode(',', array_fill(0, count($submission_ids), '?'));
    #var_dump($submissionids_placeholder);
    #var_dump($submission_ids);

    #ora ho gli studenti da cui posso prendere gli id
    #ora ho le submission con le id
    #devo prendere tutte le righe da mdl_advwork_assessment che hanno "submissionid" che compare in $submissions_ids
    #su questo nuovo array controllo il review array

    $sql = "
        SELECT *
        FROM {advwork_assessments}
        WHERE submissionid IN ($submissionids_placeholder)
    ";

    // Esegui la query con i submission IDs come parametri
    $advwork_assessments = $DB->get_records_sql($sql, $submission_ids);

    var_dump($advwork_assessments);
    /*
    foreach ($enrolled_students as $student) {
        $reviewerid = $student->id; // Ottieni l'ID dello studente
        
        // Prepara la query per contare le occorrenze del reviewerid nella tabella mdl_advwork_assessments
        $sql = "
            SELECT COUNT(*)
            FROM {advwork_assessments}
            WHERE reviewerid = :reviewerid
            AND submissionid IN ($submissionids_placeholder)
        ";

        // Imposta i parametri per la query
        $params = ['reviewerid' => $reviewerid];
        
        // Aggiungi gli ID delle submission ai parametri
        $params = array_merge($params, $advwork_submissions);

        // Esegui la query e ottieni il conteggio
        $count = $DB->count_records_sql($sql, $params);

        // Controlla se il conteggio è esattamente tre
        if ($count === 3) {
            $result[] = $reviewerid; // Aggiungi l'ID allo stato se è presente tre volte
        }
    }

    // Controlla se il numero di elementi in $result è uguale al numero di $enrolled_students
    if (count($result) === count($enrolled_students)) {
        return "completed"; // Restituisce "completed" se tutti gli studenti hanno tre valutazioni
    } else {
        return "no"; // Restituisce null o un messaggio vuoto se non sono tutti completati
    }
    */
}

function create_allocation_among_groups() {
    #si agisce su mdl_advowrk_assessments
    #random_allocation($authors, $reviewers, $assessments, $result, array $options)
    return;
}
#OUTPUT STARTS HERE

echo $output->header();
echo $output->heading(format_string('Simulated Students'));
?>


<div class="container">
    <p>Course Name: <?php echo $course->fullname;?>, ID: <?php echo $courseid;?></p>
    <p>Module Name: <?php echo $advwork->name;?>, ID: <?php echo $advwork->id; ?></p>
</div>

<div class="container">
    <p>Total number of simulated students: <?php echo read_how_many_sim_students_already_exists('sim_student', false); ?></p>
    <p>
        <form action="simulationclass.php?id=<?php echo $id; ?>" method="POST">
            <div class="row d-flex align-items-center">
                <div class ="col-3">How many students to create:</div>
                <div class ="col-2"><input type="number" name="students_number_to_create"></div>
                <div class ="col"><button type="submit" class="btn btn-primary" name="create_students_btn">Create Simulated Students</button></div>
            </div>
        </form>
        <?php echo display_function_message($message_create); ?>
    </p>
</div>

<div class="container">
    <p>How many simulated students enrolled on this course: <?php echo get_students_in_course($courseid, false); ?></p>
    <p>
        <form action="simulationclass.php?id=<?php echo $id; ?>" method="POST">
            <div class="row d-flex align-items-center">
                <div class ="col-3">How many students to enroll in total:</div>
                <div class ="col-2"><input type="number" name="students_number_to_enroll"></div>
                <div class ="col"><button type="submit" class="btn btn-primary" name="enroll_students_btn">Enroll Simulated Students</button></div>
            </div>
        </form>
        <?php echo display_function_message($message_enroll); ?>
    </p>
</div>

<div class="container">
    <p>
        <form action="simulationclass.php?id=<?php echo $id; ?>" method="POST">
            <div class="row d-flex align-items-center">
                <div class ="col-5">Submissions number: <?php echo get_submissions($advwork->id, false);?> / <?php 
                    echo get_students_in_course($courseid, false); ?></div>
                <div class ="col"><button type="submit" class="btn btn-primary" name="create_submissions_btn">Create Simulated Submissions</button></div>
            </div>
        </form>
        <?php echo display_function_message($message_submission);?>
    </p>
</div>

<div class="container">
    <p>
        <form action="simulationclass.php?id=<?php echo $id; ?>" method="POST">
            <div class="row d-flex align-items-center">
                <div class ="col-5">
                    <div>Active groups: <?php echo count_groups_in_course($courseid);?></div>
                    <div>Group dimension: 4</div>
                    <div>Active grouping: <?php echo get_course_grouping_name($courseid); ?></div>
                </div>
                <div class="col">
                    <div><button type="submit" class="btn btn-primary" name="create_groups_btn">Create Groups</button></br></div>
                    <div><p></p></div>
                    <div><button type="submit" class="btn btn-primary" name="create_grouping_btn">Create Grouping</button></div>
                </div>
            </div>
        </form>
        <?php echo display_function_message($message_groups);?>
    </p>
</div>

<div class="container">
    <p>
        <form action="simulationclass.php?id=<?php echo $id; ?>" method="POST">
            <div class="row d-flex align-items-center">
                <div class ="col-5">Allocation made: <?php echo check_the_allocation($courseid, $advwork->id);?></div>
                <div class ="col"><button type="submit" class="btn btn-primary" name="create_allocation_btn">Random Reviewers Allocation</button></div>
            </div>
        </form>
        <?php echo display_function_message($message_allocation);?>
    </p>
</div>

<button type="button" class="btn btn-light" id=""><a href="view.php?id=<?php echo $id; ?>">Back to ADVWORKER: View</a></button>


<?php 
$PAGE->requires->js_call_amd('mod_advwork/advworkview', 'init');
echo $output->footer();
?>
