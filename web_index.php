<?php
/*
 * EZCAST EZrecorder
 *
 * Copyright (C) 2014 Université libre de Bruxelles
 *
 * Written by Michel Jansens <mjansens@ulb.ac.be>
 * 	      Arnaud Wijns <awijns@ulb.ac.be>
 *            Antoine Dewilde
 * UI Design by Julien Di Pietrantonio
 *
 * This software is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This software is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this software; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/* ezcast recorder main program (MVC controller)
 *
 */
// Inits
//
include_once 'global_config.inc';

session_start();

error_reporting(E_PARSE | E_ERROR);

require_once $auth_lib;
require_once 'lib_error.php';
require_once 'lib_template.php';
if ($cam_enabled)
    require_once $cam_lib; // defined in config_modules.inc
if ($slide_enabled)
    require_once $slide_lib; // defined in config_modules.inc
require_once $session_lib;
if ($cam_management_enabled)
    require_once $cam_management_lib;

$input = array_merge($_GET, $_POST);
$template_folder = 'tmpl/';

template_repository_path($template_folder . get_lang());
template_load_dictionnary('translations.xml');

//
// Controller
//
// Login/logout
// If we're not logged in, we try to log in or display the login form
if (!user_logged_in()) {
    
    // If an "action" was given, it means we've already submitted the login form
    // So all we want to do is check whether there is still a "forgotten" recording
    // and if not, log the user in
    if (isset($input['action']) && $input['action'] == 'login') {
        if (!isset($input['login']) || !isset($input['passwd'])) {
            //echo 'Login error: no login/password provided';
            echo template_get_message('Empty_username_password', get_lang());
            die;
        }

        user_login($input['login'], $input['passwd']);
    // If the action is 'recording_force_quit', it might be a request from 
    // the recorder. The quicktime modules, for instance, use curl to
    // stop the recording after a timeout. 
    } else if ($input['action'] == 'recording_force_quit') {
        // We get information from both cam and slide modules to make 
        // sure that the caller ip address is one of the recorders. 
        // It is an access restriction on ip address
        if ($cam_enabled) {
            $fct = "capture_" . $cam_module . "_download_info_get";
            $cam_info = $fct();
        }
        if ($slide_enabled) {
            $fct = "capture_" . $slide_module . "_download_info_get";
            $slide_info = $fct();
        }
        $caller_ip = trim($_SERVER["REMOTE_ADDR"]);
        // if caller ip is one of the recorders (cam or slide), the current recording is stopped
        if ($caller_ip == $cam_info['ip'] || $caller_ip == $slide_info['ip']) {
            recording_force_quit();
        // else (it is a standard user), the user has to log in
        } else {
            view_login_form();
        }
    }

    // At this point of the code, the user hasn't even submitted a single form
    // So we display the login form to them.
    else {
        view_login_form();
    }
    die;
}

    // Check if the asset is known
    // The asset is not known if the session has been force quit,
    // if the session has expired or if there is a remote
    // control of the session    
    $fct_session_is_locked = "session_" . $session_module . "_is_locked";
    if(!isset($_SESSION['asset']) && $fct_session_is_locked()){
        $session = explode(';',file_get_contents($recorder_session));
        if ($_SESSION['user_login'] == $session[1]){
            $_SESSION['asset'] = $session[0];
        }
    }

// At this point of the code, we know the user is logged in.
// So now, we must see what action they wanted to perform, and do it.
$action = $input['action'];
switch ($action) {
    
    // Someone submitted record information.
    // We save these metadata and display the record_screen
    case 'submit_record_infos':
        recording_submit_infos();
        break;

    // Displays the screenshot iframe for visual feedback
    case 'view_screenshot_iframe':
        view_screenshot_iframe();
        break;

    case 'view_screenshot_image':
        view_screenshot_image();
        break;

    case 'view_login_form':
        view_login_form();
        break;

    case 'view_record_form':
        view_record_form();
        break;

    case 'view_record_submit':
        view_record_submit();
        break;

    // Starts recording
    case 'recording_start':
        recording_start();
        break;

    // Stops recording
    case 'recording_stop':
        recording_stop();
        break;

    // Discards a record
    case 'recording_cancel':
        recording_cancel();
        break;

    case 'recording_pause':
        recording_pause();
        break;

    case 'recording_resume':
        recording_resume();
        break;

    // Case when someone asks to log in while someone else was recording
    case 'recording_force_quit':
        recording_force_quit();
        break;

    case 'camera_move':
        camera_move();
        break;

    case 'logout':
        user_logout();
        break;
    // At this point of the code, we know the user is logged in, but for some reason they didn't provide an action.
    // That means they manually reloaded the page. In this case, we bring them back from where they came.
    default:
        reconnect_active_session();
}

//
// Model functions
//

/**
 * We are called by a browser with no action, but sessions is alive and login has succeeded, so go to thecaccording screen
 * @global <type> $status
 * @global <type> $already_recording
 */
function reconnect_active_session() {
    global $status;
    global $already_recording;
    global $redraw;

    log_append("Reconnect active session");
    $status = status_get();
    //lets check what the 'real' state we're in
    $already_recording = ($status == 'recording' || $status == 'paused');
    if ($already_recording || $status == 'open') {
        //state is one of the recording mode
        $_SESSION['recorder_mode'] = 'view_record_screen';
        $redraw = true;
        view_record_screen();
    } else if ($status == 'stopped') {
        //stopped means we have already clicked on stop
        $_SESSION['recorder_mode'] = 'view_record_submit';
        view_record_submit();
    }
    else
        view_record_form(); //none of the above cases to this is a first form screen
}

/**
 * Handles recording_form values and open recorder_view
 * @global type $input
 * @global <type> $classroom
 */
function recording_submit_infos() {
    global $input;
    global $classroom;
    global $auth_module;
    global $session_module;
    global $dir_date_format;
    global $recorder_session;

    // Sanity checks
    if (!isset($input['title']) || empty($input['title'])) {
        error_print_message(template_get_message('title_not_defined', get_lang()), false);
        die;
    }

    if (!isset($input['record_type']) || empty($input['record_type'])) {
        error_print_message(template_get_message('type_not_defined', get_lang()), false);
        die;
    }

    // authorization check
    $fct_user_has_course = "auth_" . $auth_module . "_user_has_course";
    if (!$fct_user_has_course($_SESSION['user_login'], $input['course'])) {
        error_print_message('You do not have permission to access course ' . $input['course'], false);
        log_append('warning', 'submit_record_infos: ' . $_SESSION['user_login'] . ' tried to access course ' . $input['course'] . ' without permission');
        die;
    }
    $_SESSION['recorder_course'] = $input['course'];
    $_SESSION['recorder_type'] = $input['record_type'];

    $datetime = date($dir_date_format);

    // Now we create and store the metadata
    $record_meta_assoc = array(
        'course_name' => $input['course'],
        'origin' => $classroom,
        'title' => trim($input['title']),
        'description' => $input['description'],
        'record_type' => $input['record_type'],
        'moderation' => 'true',
        'author' => $_SESSION['user_full_name'],
        'netid' => $_SESSION['user_login'],
        'record_date' => $datetime
    );


    $fct_metadata_save = "session_" . $session_module . "_metadata_save";
    $res = $fct_metadata_save($record_meta_assoc);

    if (!$res) {
        error_print_message('submit_record_infos: something went wrong while saving metadata');
        die;
    }

    log_append("submit info from recording form");
    // Don't forget to save the current viewed page into a session var, just in cast the user reloads the page
    $_SESSION['recorder_mode'] = 'view_record_screen';
    $_SESSION['asset'] = $record_meta_assoc['record_date'] . '_' . $record_meta_assoc['course_name'];
    file_put_contents($recorder_session, $_SESSION['asset'].";".$_SESSION['user_login']);

    // And finally we can display the main screen!
    view_record_screen();
}

/**
 * Starts a new recording
 */
function recording_start() {
    global $dir_date_format;
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $session_module;

    // another user is connected
    $fct_current_user_get = "session_" . $session_module . "_current_user_get";
    $user = $fct_current_user_get();

    if ($user != $_SESSION['user_login']) {
        error_print_message('User conflict - session user [' . $_SESSION['user_login'] . '] different from current user [' . $user . '] : check permission on current_user file in session module' );
        die;
    }

    //get current status and check if its compatible with current action
    $status = status_get();
    if ($status == 'open') {

        // saves the start time
        $datetime = date($dir_date_format);
        $startrec_info = "$datetime\n";
        $startrec_info.=$_SESSION['recorder_course'] . "\n";
        $fct_recstarttime_set = "session_" . $session_module . "_recstarttime_set";
        $fct_recstarttime_set($startrec_info);

        // determines if the slide module is enabled
        if ($slide_enabled) {
            $fct_capture_start = 'capture_' . $slide_module . '_start';
            // ideally, capture_start should return the pid
            //     $res_slide = $fct_capture_start($slide_pid);
            $res_slide = $fct_capture_start($_SESSION['asset']);
        }

        // determines if the cam module is enabled (doesn't depend on the 
        // recording format chose by user - cam, slide, camslide)
        if ($cam_enabled) {
            $fct_capture_start = 'capture_' . $cam_module . '_start';
            // ideally, capture_start should return the pid
            // $res_cam = $fct_capture_start($cam_pid);
            $res_cam = $fct_capture_start($_SESSION['asset']);
        }

        //      while(is_process_running($cam_pid) || is_process_running($slide_pid))
        //          sleep(0.5);
        // something went wrong while starting the recording
        if (($cam_enabled && !$res_cam) || ($slide_enabled && !$res_slide)) {
            error_print_message(capture_last_error());
            die;
        }

        log_append("recording_start", "started recording by user request");

        // We start recording
    } else {
        error_print_message("capture_start: error status ($status): status not 'open'");
        die;
    }
}

/**
 * Stops the recording and processes it
 */
function recording_stop() {
    global $input;
    global $php_cli_cmd;
    global $process_upload;
    global $session_module;
    global $basedir;

    $moderation = 'false';
    if (isset($input['moderation']) && $input['moderation'] == 'true')
        $moderation = 'true';

    // Logging the operation
    $fct_recstarttime_get = "session_" . $session_module . "_recstarttime_get";
    $recstarttime = explode(PHP_EOL, $fct_recstarttime_get());
    $starttime = $recstarttime[0];
    $album = $recstarttime[1];
    log_append('recording_stop', 'Stopped recording by user request (course ' . $album . ', started on ' . $starttime . ', moderation: ' . $moderation . ')');

    //get the start time and course from metadata
    $fct_metadata_get = "session_" . $session_module . "_metadata_get";
    $meta_assoc = $fct_metadata_get();

    $tmp_dir = $basedir . "/var/" . $_SESSION['asset'];
    mkdir($tmp_dir);

    //update metadata with moderation
    if ($moderation == 'true' || $moderation == 'false') {
        $meta_assoc['moderation'] = $moderation;
        $fct_metadata_save = "session_" . $session_module . "_metadata_save";
        $fct_metadata_save($meta_assoc);
    }

    $fct_metadata_xml_get = "session_" . $session_module . "_metadata_xml_get";
    $meta_xml_string = $fct_metadata_xml_get();
    // saves the recording metadata in a tmp xml file (used later in cli_process_upload.php)
    file_put_contents("$tmp_dir/metadata.xml", $meta_xml_string);

    // launches the video processing in background
    //  exec("echo '$php_cli_cmd $process_upload' | at now", $output, $errno); // delay is too long using cmd at
    exec("$php_cli_cmd $process_upload > /dev/null &", $output, $errno);

    // releases the recording session
    $fct_session_unlock = "session_" . $session_module . "_unlock";
    $fct_session_unlock();

    // And finally, closing the user's session
    session_destroy();

    // Displaying a confirmation message
    require_once template_getpath('div_record_submitted.php');
}

/**
 * Cancel the recording after the record form has been submitted or at the 
 * end of the recording
 * @global type $recstarttime_file
 * @global type $cam_enabled
 * @global type $cam_module
 * @global type $slide_enabled
 * @global type $slide_module
 * @global type $visca_enabled
 * @global type $input
 * @return boolean
 */
function recording_cancel() {
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $cam_management_enabled;
    global $cam_management_module;
    global $input;
    global $session_module;

    // Logging the operation
    $fct_recstarttime_get = "session_" . $session_module . "_recstarttime_get";
    $recstarttime = explode(PHP_EOL, $fct_recstarttime_get());
    $starttime = $recstarttime[0];
    $album = $recstarttime[1];
    log_append('recording_cancel', 'Deleted recording by user request (course ' . $album . ', started on ' . $starttime . ')');

    // Stopping and releasing the recorder
    // if cam module is enabled
    if ($cam_enabled) {
        $fct_capture_cancel = 'capture_' . $cam_module . '_cancel';    
        $res_cam = $fct_capture_cancel($_SESSION['asset']);
    }
    // if slide module is enabled 
    if ($slide_enabled) {
        $fct_capture_cancel = 'capture_' . $slide_module . '_cancel';
        $res_slide = $fct_capture_cancel($_SESSION['asset']);
    }

    if ($cam_management_enabled) {
        //cam management enabled so try to put camera back in place
        $fct_cam_move = "cam_" . $cam_management_module . "_move";
        $fct_cam_move($GLOBALS['cam_default_scene']); // set cam to the initial position
    }

    // something wrong happened while cancelling the recording
    if (($cam_enabled && !$res_cam) || ($slide_enabled && !$res_slide)) {
        error_print_message(error_last_message());
        return false;
    }

    // releases the recording session. Someone else can now record
    $fct_session_unlock = "session_" . $session_module . "_unlock";
    $fct_session_unlock();

    $fct_metadata_delete = "session_" . $session_module . "_metadata_delete";
    $fct_metadata_delete();

    //closing the user's session
    session_destroy();
    status_set('');

    // Displaying a confirmation message
    require_once template_getpath('div_record_cancelled.php');
}

/**
 * Function called when someone tries to log in, but someone else was already recording
 */
function recording_force_quit() {
    global $notice;
    global $cam_enabled;
    global $slide_enabled;
    global $cam_module;
    global $slide_module;
    global $session_module;
    global $php_cli_cmd;
    global $process_upload;
    global $tmp_meta_file;
    global $basedir;

    $fct_current_user_get = "session_" . $session_module . "_current_user_get";
    log_append('warning', $_SESSION['user_login'] . ' trying to log in but recorder is already in use by ' . $fct_current_user_get() . '. Stopping current record.');
    $status = status_get();
    if ($status == '' || $status == 'open') {
        // if cam module is enabled
        if ($cam_enabled) {
            $fct_capture_cancel = 'capture_' . $cam_module . '_cancel';
            $res_cam = $fct_capture_cancel($_SESSION['asset']);
        }
        // if slide module is enabled
        if ($slide_enabled) {
            $fct_capture_cancel = 'capture_' . $slide_module . '_cancel';
            $res_slide = $fct_capture_cancel($_SESSION['asset']);
        }
        // deletes the previous metadata file 
        $fct_metadata_delete = "session_" . $session_module . "_metadata_delete";
        $fct_metadata_delete();
    } else { // a recording is pending (or stopped)
        // Logging the operation
        $fct_recstarttime_get = "session_" . $session_module . "_recstarttime_get";
        $recstarttime = explode(PHP_EOL, $fct_recstarttime_get());
        $starttime = $recstarttime[0];
        $album = $recstarttime[1];
        log_append('recording_force_quit', 'Force quit recording by another user [' . $_SESSION['user_login'] . '] (course ' . $album . ', started on ' . $starttime . ')');

    $tmp_dir = $basedir . "/var/" . $_SESSION['asset'];
    mkdir($tmp_dir);
        
        $fct_metadata_xml_get = "session_" . $session_module . "_metadata_xml_get";
        $meta_xml_string = $fct_metadata_xml_get();
        // saves the recording metadata in a tmp xml file (used later in cli_process_upload.php)
        file_put_contents($tmp_dir . "/metadata.xml", $meta_xml_string);

        // Stopping (pausing) the recording
        // if slide module is enabled
        if ($slide_enabled) {
            $fct_capture_stop = 'capture_' . $slide_module . '_stop';
            $fct_capture_stop($slide_pid, $asset);
        }
        // if cam module is enabled
        if ($cam_enabled) {
            $fct_capture_stop = 'capture_' . $cam_module . '_stop';
            $fct_capture_stop($cam_pid, $asset);
        }

        // waits until both processes are finished to continue.
        while (is_process_running($cam_pid) || is_process_running($slide_pid))
            sleep(0.5);

        // launches the video processing in background
        // exec("$php_cli_cmd $process_upload' | at now", $output, $errno);
        exec("$php_cli_cmd $process_upload > /dev/null &", $output, $errno);
    }

    // reinits the recording status
    status_set('');

    // releases the recording session. Someone else can now record
    $fct_session_unlock = "session_" . $session_module . "_unlock";
    $fct_session_unlock();

    template_load_dictionnary('translations.xml');
    $notice = template_get_message('ongoing_record_interrupted_message', get_lang()); // Message to display on top of the page, warning the user that they just stopped someone else's record*/

    $fct_session_lock = "session_" . $session_module . "_lock";
    $res = $fct_session_lock($_SESSION['user_login']);

    if (!$res) {
        error_print_message('Could not lock recorder: ' . error_last_message());
        die;
    }

    log_append('login');

    // 4) And finally, we can display the record form
    view_record_form();
}

/*
 * Pauses the current recording
 */

function recording_pause() {
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $session_module;
    
    // if cam module is enabled
    if ($cam_enabled) {
        $fct_capture_pause = 'capture_' . $cam_module . '_pause';
        $res_cam = $fct_capture_pause($_SESSION['asset']);
    }

    // if slide module is enabled
    if ($slide_enabled) {
        $fct_capture_pause = 'capture_' . $slide_module . '_pause';
        $res_slide = $fct_capture_pause($_SESSION['asset']);
    }

    // if something wrong happened while pausing the recording
    if (!$res_cam || !$res_slide) {
        error_print_message(error_last_message());
        die;
    }

    log_append("paused recording by request");
    echo '';
}

/*
 * Resumes the current recording
 */

function recording_resume() {
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $session_module;

    
    // if cam module is enabled 
    if ($cam_enabled) {
        $fct_capture_resume = 'capture_' . $cam_module . '_resume';
        $res_cam = $fct_capture_resume($_SESSION['asset']);
    }
    // if slide module is enabled
    if ($slide_enabled) {
        $fct_capture_resume = 'capture_' . $slide_module . '_resume';
        $res_slide = $fct_capture_resume($_SESSION['asset']);
    }

    // if something wrong happened while resuming the recording
    if (!$res_cam || !$res_slide) {
        error_print_message(error_last_message());
        die;
    }

    log_append("resumed recording by request");

    echo '';
}

/**
 * Moves the camera to the position given as a POST parameter (position name)
 * @global type $input 
 */
function camera_move() {
    global $input;
    global $cam_management_module;

    if (!isset($input['position'])) {
        error_print_message('Asked to move camera but no position given');
        die;
    }

    $scene = $input["position"];
    $fct_cam_move = "cam_" . $cam_management_module . "_move";
    $fct_cam_move($scene);
    log_append("camera moved to position : $scene");
}

//
// Functions calling the view
//

/**
 * Displays the login form
 */
function view_login_form() {
    global $url;
    session_destroy();
    require_once template_getpath('login.php');
    die;
}

/**
 * Displays the form people get when they log in (i.e. asking for a title, description, ...)
 */
function view_record_form() {
    global $input;
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $cam_management_enabled;
    global $cam_management_module;
    global $session_module;
    global $auth_module;
    global $notice; // Possible errors that occurred at previous steps.
    //
    // Retrieving the course list (to display in the web interface)
    $fct_user_courselist_get = "auth_" . $auth_module . "_user_courselist_get";
    $courselist = $fct_user_courselist_get($_SESSION['user_login']);

    if ($input['reset_player'] == 'true') {
        // if cam module is enabled
        if ($cam_enabled) {
            $fct_capture_cancel = 'capture_' . $cam_module . '_cancel';
            $fct_capture_cancel($_SESSION['asset']);
        }
        // if slide module is enabled
        if ($slide_enabled) {
            $fct_capture_cancel = 'capture_' . $slide_module . '_cancel';
            $fct_capture_cancel($_SESSION['asset']);
        }

        if (status_get() != 'open') {
            $fct_metadata_delete = "session_" . $session_module . "_metadata_delete";
            $fct_metadata_delete();
        }

        status_set('');

        if ($cam_management_enabled) {
            //cam management enabled so try to put camera back in place
            $fct_cam_move = 'cam_' . $cam_management_module . '_move';
            $fct_cam_move($GLOBALS['cam_default_scene']); //set ptz to the initial position
        }
    }

    $fct_metadata_get = "session_" . $session_module . "_metadata_get";
    $metadata = $fct_metadata_get();

    $_SESSION['recorder_course'] = $metadata['course_name'];
    $_SESSION['title'] = $metadata['title'];
    $_SESSION['description'] = $metadata['description'];
    $_SESSION['recorder_type'] = $metadata['record_type'];

    require_once template_getpath('record_form.php');
}

//
// Helper functions
//

/**
 * Helper function
 * @return bool true if the user is already logged in; false otherwise
 */
//TODO: The function doesn't check anything for now
function user_logged_in() {
    return isset($_SESSION['recorder_logged']);
}

/**
 * Logs a user in
 */
function user_login($login, $passwd) {
    global $input;
    global $template_folder;
    global $notice;
    global $redraw;
    global $already_recording;
    global $status;
    global $session_module;
    global $auth_module;

    // 0) Sanity checks
    if (empty($login) || empty($passwd)) {
        $error = template_get_message('Empty_username_password', get_lang());
        require_once template_getpath('login.php');
        die;
    }

    // 1) We check the user's identity and retrieve their personal information
    $fct_auth_check = "auth_" . $auth_module . "_check";
    $res = $fct_auth_check($login, $passwd);
    if (!$res) {
        $fct_auth_last_error = "auth_" . $auth_module . "_last_error";
        $error = $fct_auth_last_error();
        require_once template_getpath('login.php');
        die;
    }

    // 2) Now we can set the session variables
    $_SESSION['recorder_logged'] = 'LEtimin'; // "Boolean" telling that we're logged in
    $_SESSION['user_login'] = $res['user_login'];
    $_SESSION['user_real_login'] = $res['real_login'];
    $_SESSION['user_full_name'] = $res['full_name'];
    $_SESSION['user_email'] = $res['email'];
    set_lang($input['lang']);
    template_repository_path($template_folder . get_lang());

    // 3) Now we have to check whether or not there is still a recording ongoing.
    // For that, we check the _current_user file. If it exists, it means there is already
    // a recording ongoing. If the user who started the recording is the one trying to log in again,
    // then we display the recording screen again. If not, then we stop the current recording
    // and display the record_form.
    $fct_session_is_locked = "session_" . $session_module . "_is_locked";
    $session_locked = $fct_session_is_locked();
    if ($session_locked) {
        $fct_current_user_get = "session_" . $session_module . "_current_user_get";
        $current_user = $fct_current_user_get();
        if ($_SESSION['user_login'] == $current_user) {
            // We retrieve the recorder page
            log_append('reconnecting', $_SESSION['user_login'] . ' trying to log in but was already using recorder. Retrieving lost session.');

            $redraw = true;
            $status = status_get();
            $already_recording = ($status == 'recording' || $status == 'paused');
            if ($status == 'recording' || $status == 'paused' || $status == 'open')
                view_record_screen(); //go directly to record screen
            else if ($status == 'stopped')
                view_record_submit();
            else
                view_record_form(); //ask metadata again
            die;
        }
        // Case where someone else is trying to connect while someone is already using the recorder
        else {
            // We ask the user if they want to stop the current recording and save it.
            // Various information we want to display
            $fct_current_user_get = "session_" . $session_module . "_current_user_get";
            $current_user = $fct_current_user_get();

            $fct_recstarttime_get = "session_" . $session_module . "_recstarttime_get";
            $recstarttime = explode(PHP_EOL, $fct_recstarttime_get());
            $start_time = $recstarttime[0];
            $course = $recstarttime[1];

            $start_time = trim($start_time);
            $course = trim($course);
            require_once template_getpath('div_error_recorder_in_use.php');
            die;
        }
    }
    $fct_session_lock = "session_" . $session_module . "_lock";
    $res = $fct_session_lock($res['user_login']);

    if (!$res) {
        error_print_message('Could not lock recorder: ' . error_last_message());
        die;
    }

    log_append('login');

    // 4) And finally, we can display the record form
    view_record_form();
}

/**
 * Displays the screen with "pause/resume", video feedback, etc.
 */
function view_record_screen() {
    global $url;
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $cam_management_enabled;
    global $cam_management_module;
    global $cam_management_views_dir;
    global $session_module;
    global $redraw;
    global $already_recording;
    global $status;

    $fct_metadata_get = "session_" . $session_module . "_metadata_get";
    $metadata = $fct_metadata_get();
    //get status of recording (from file)
    $status = status_get();
    // 1) First of all we init the recorder
    if ($status == '') {

        if ($cam_management_enabled) {
            //cam management enabled so try to put camera back in place
            if ($_SESSION['recorder_type'] == 'slide') {
                $fct_cam_move = "cam_" . $cam_management_module . "_move";
                $fct_cam_move($GLOBALS['cam_screen_scene']); //if slide only, record screen as a backup
            } else {
                $fct_cam_move = "cam_" . $cam_management_module . "_move";
                $fct_cam_move($GLOBALS['cam_default_scene']); //set ptz to the initial position
            }
        }

        // if cam module is enabled
        if ($cam_enabled) {
            $fct_capture_init = 'capture_' . $cam_module . '_init';
            $res_cam = $fct_capture_init($cam_pid, $metadata);
        }
        // if slide module is enabled
        if ($slide_enabled) {
            $fct_capture_init = 'capture_' . $slide_module . '_init';
            $res_slide = $fct_capture_init($slide_pid, $metadata);
        }

        // capture_init is launched in background in order to save time.
        // waits until both processes are finished to continue.
        while (is_process_running($cam_pid) || is_process_running($slide_pid))
            sleep(0.5);

        // something wrong happened while init the recorders
        //    if (($cam_enabled && !$res_cam) || ($slide_enabled && !$res_slide)) {
        // if QTB launch failed, reset status and display an error box
        $status = status_get();
        if ($status == 'error' || $status == 'launch_failure') {
            status_set('open');
            require_once template_getpath('div_error_launch_failure.php');
            //    die;
            /*        } else {
              error_print_message(error_last_message());
              die;
              }
             */
        }
    }

    // Then we set up some variables
    if ($cam_management_enabled) {
        $fct_cam_posnames_get = "cam_" . $cam_management_module . "_posnames_get";
        $positions = $fct_cam_posnames_get(); // List of camera positions available (used in record_screen.php)
    }
    // DIsplaying a "disabled" image if one of the two video sources has been disabled
    $has_camera = (strpos($metadata['record_type'], 'cam') !== false);
    $has_slides = (strpos($metadata['record_type'], 'slide') !== false);

    log_append("recording_init", "initiated recording by request (record_type: " .
            $metadata['record_type'] . " - cam module enabled : $cam_enabled - slide module enabled : $slide_enabled");

    // And finally we display the page
    require_once template_getpath('record_screen.php');
}

/*
 * After stopping the recording
 */

function view_record_submit() {
    global $url;
    global $cam_management_enabled;
    global $cam_management_module;
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    // Stopping (pausing) the recording
    // if slide module is enabled
    if ($slide_enabled) {
        $fct_capture_stop = 'capture_' . $slide_module . '_stop';
        $fct_capture_stop($slide_pid, $_SESSION['asset']);
    }
    // if cam module is enabled
    if ($cam_enabled) {
        $fct_capture_stop = 'capture_' . $cam_module . '_stop';
        $fct_capture_stop($cam_pid, $_SESSION['asset']);
    }

    // waits until both processes are finished to continue.
    while (is_process_running($cam_pid) || is_process_running($slide_pid))
        sleep(0.5);

    $_SESSION['recorder_mode'] = 'view_record_submit';

    if ($cam_management_enabled) {
        //cam management enabled so try to put camera back in place
        $fct_cam_move = "cam_" . $cam_management_module . "_move";
        $fct_cam_move($GLOBALS['cam_default_scene']); //set ptz to the initial position
    }
    // And displaying the submit form
    require_once template_getpath('record_submit.php');
}

function view_screenshot_iframe() {
    global $input;

    $source = "cam";
    if ($input['source'] == 'slides')
        $source = 'slides';

    require_once template_getpath('iframe_screenshot.php');
    //require_once 'screenshot.php';
}

function view_screenshot_image() {
    global $input;
    global $cam_enabled;
    global $cam_module;
    global $slide_enabled;
    global $slide_module;
    global $nopic_file;

    if (isset($input['source']) && in_array($input['source'], array('cam', 'slides'))) {
        if ($input['source'] == 'cam' && $cam_enabled) {
            $fct_capture_thumbnail = 'capture_' . $cam_module . '_thumbnail';
            $pic = $fct_capture_thumbnail();
        } else if ($input['source'] == "slides" && $slide_enabled) {
            $fct_capture_thumbnail = 'capture_' . $slide_module . '_thumbnail';
            $pic = $fct_capture_thumbnail();
        }
    }
    if (!isset($pic) || $pic == '') {
        $pic = file_get_contents($nopic_file);
    }

    header('Content-Type: image/jpeg');
    echo $pic;
}

/**
 * Logs the user out, i.e. destroys all the data stored about them
 */
function user_logout() {
    global $template_folder;
    global $session_module;
    //unlock interface
    $fct_session_unlock = "session_" . $session_module . "_unlock";
    $fct_session_unlock();
    
    log_append("user logged out");

    //destroy session
    session_destroy();
    $fct_metadata_delete = "session_" . $session_module . "_metadata_delete";
    $fct_metadata_delete();
    // 3) Displaying the logout message
    //include_once template_getpath('logout.php');
    include_once "tmpl/fr/logout.php";

    $url = $application_url;

    include_once template_getpath('logout.php');
}

//
// Helper functions
//

/**
 * Returns current chosen language
 * @return string(fr|en) 
 */
function get_lang() {
    //if(isset($_SESSION['lang']) && in_array($_SESSION['lang'], $accepted_languages)) {
    if (isset($_SESSION['lang']) && !empty($_SESSION['lang'])) {
        return $_SESSION['lang'];
    }
    else
        return 'en';
}

/**
 * Sets the current language to the one chosen in parameter
 * @param type $lang 
 */
function set_lang($lang) {
    $_SESSION['lang'] = $lang;
}

/*
 * returns the status of current recording.
 * Status is set in each module. If status is not the same in every modules,
 * returns an "error" status.
 */

function status_get() {
    global $cam_enabled;
    global $slide_enabled;
    global $cam_module;
    global $slide_module;

    $cam_status;
    $slide_status;

    if ($cam_enabled) {
        $fct_status_get = 'capture_' . $cam_module . '_status_get';
        $cam_status = $fct_status_get();
    }


    if ($slide_enabled) {
        $fct_status_get = 'capture_' . $slide_module . '_status_get';
        $slide_status = $fct_status_get();
    }

    if ($slide_enabled && $cam_enabled) {
        if ($cam_status == $slide_status)
            return $cam_status;
        else
            return "error";
    } else if ($slide_enabled) {
        return $slide_status;
    } else if ($cam_enabled) {
        return $cam_status;
    }
}

/*
 * Sets current status for all enabled modules.
 */

function status_set($status) {
    global $cam_enabled;
    global $slide_enabled;
    global $cam_module;
    global $slide_module;

    if ($cam_enabled) {
        $fct_status_set = 'capture_' . $cam_module . '_status_set';
        $fct_status_set($status);
    }


    if ($slide_enabled) {
        $fct_status_set = 'capture_' . $slide_module . '_status_set';
        $fct_status_set($status);
    }
}

// determines if a process is running or not
function is_process_running($pid) {
    if (!isset($pid) || $pid == '' || $pid == 0)
        return false;
    exec("ps $pid", $output, $result);
    return count($output) >= 2;
}

?>
