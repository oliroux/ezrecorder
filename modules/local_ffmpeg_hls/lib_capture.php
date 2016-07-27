<?php

/*
 * EZCAST EZrecorder
 *
 * Copyright (C) 2014 Université libre de Bruxelles
 *
 * Written by Michel Jansens <mjansens@ulb.ac.be>
 * 	      Arnaud Wijns <awijns@ulb.ac.be>
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

require 'etc/config.inc';
require_once __DIR__ . '/../../global_config.inc';
require_once $basedir . '/common.inc';
require_once $basedir . '/lib_various.php';
require_once 'create_bash_configs.php';
include_once $basedir . '/lib_error.php';

$module_name = "capture_ffmpeg";
/*
 * This file contains all functions related to the video capture from an analog camera.
 * It implements the "recorder interface" which is used in web_index.
 * the function annotated with the comment "@implements" are required to make
 * sure the web_index.php can work properly.
 */

/**
 * @implements
 * Initialize the recording settings.
 * This function should be called before the use of the camera.
 * This function should launch a background task to save time and keep syncro
 * between cam and slides (if both are available)
 * @param int $pid the process id of the background task. This is updated to process pid if function is successfull
 * @param associative_array $meta_assoc Metadata related to the record (used in cli_monitoring.php)
 * @return boolean true if everything went well; false otherwise
 */
function capture_ffmpeg_init(&$pid, $meta_assoc) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_init;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;
    global $ezcast_submit_url;
    global $ffmpeg_streaming_info;
    global $ffmpeg_streaming_quality;
    global $ffmpeg_input_source;
    global $php_cli_cmd;
    global $ffmpeg_cli_streaming;
    global $bash_env;
    global $ffmpeg_basedir;

    $asset = $meta_assoc['record_date'] . '_' . $meta_assoc['course_name'];
    
    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name), $asset, null);
    
    //prepare bash variables
    $success = create_bash_configs($bash_env, $ffmpeg_basedir . "etc/localdefs");
    if (!$success) {
        file_put_contents($ffmpeg_recorder_logs, "capture_ffmpeg_init: ERROR: Unable to create bash variables file\n", FILE_APPEND);
        $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Unable to create bash variables file", array("module",$module_name));
        return false;
    }

    if (file_exists($ffmpeg_streaming_info))
        unlink($ffmpeg_streaming_info);


    $tmp_dir = capture_ffmpeg_tmpdir_get($asset);

    // saves recording metadata as xml file
    assoc_array2xml_file($meta_assoc, "$tmp_dir/_metadata.xml");

    // status of the current recording, should be empty
    $status = capture_ffmpeg_status_get();
    if ($status != '') { // has a status
        error_last_message("capture_init: can't open because current status: $status");
        $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Can't init because current status: $status", array("module",$module_name));
        return false;
    }

    //if streaming is enabled, write it in '/var/streaming' ($ffmpeg_streaming_info) so that we may get the information later
    $streaming_info = capture_ffmpeg_info_get('streaming', $asset);
    if ($streaming_info !== false) {
        // defines that the streaming is enabled
        // It must be done before calling $ffmpeg_script_init (for preparing low and high HLS streams)
        file_put_contents($ffmpeg_streaming_info, var_export($streaming_info, true));
    }
    // script_init initializes FFMPEG and launches the recording
    // in background to save time (pid is returned to be handled by web_index.php)
    system("sudo -u $ezrecorder_username $ffmpeg_script_init $asset $ffmpeg_input_source 1 >> $ffmpeg_recorder_logs 2>&1 & echo $! > $tmp_dir/pid");
    $pid = file_get_contents("$tmp_dir/pid");
    
    // error occured while launching FFMPEG
    if (capture_ffmpeg_status_get() == 'launch_failure') {
        error_last_message("can't open because FFMPEG failed to launch");
        $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Can't init because FFMPEG failed to launch", array("module",$module_name));
        return false;
    }
    // the recording is now 'open'
    capture_ffmpeg_status_set('open');
    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": FFMPEG status set to 'open'", array("module",$module_name));

    // init the streaming
    if ($streaming_info !== false) {
        // streaming is enabled, we send a request to EZmanager to
        // init the streamed asset
        $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": Streaming is enabled", array("module",$module_name));

        $post_array = $streaming_info;
        $post_array['action'] = 'streaming_init';
        $result = server_request_send($ezcast_submit_url, $post_array);

        if (strpos($result, 'Curl error') !== false) {
            // an error occured with CURL
            $meta_assoc['streaming'] = 'false';
            unlink($ffmpeg_streaming_info);
            $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Curl failed to send request to server: $result", array("module",$module_name));
        }
        //not used $result = unserialize($result);
        // executes the command for sending TS segments to EZmanager in background
        // for low and high qualities
        if (strpos($ffmpeg_streaming_quality, 'high') !== false) {
            exec("$php_cli_cmd $ffmpeg_cli_streaming " . $meta_assoc['course_name']
                    . " " . $meta_assoc['record_date'] . " high > /dev/null &", $output, $errno);
        }
        if (strpos($ffmpeg_streaming_quality, 'low') !== false) {
            exec("$php_cli_cmd $ffmpeg_cli_streaming " . $meta_assoc['course_name']
                    . " " . $meta_assoc['record_date'] . " low > /dev/null &", $output, $errno);
        }
    }

    $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Successfully initialized module", array("module",$module_name));
    return true;
}

/**
 * @implements
 * Launches the recording process
 */
function capture_ffmpeg_start($asset) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_start;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;
    global $ffmpeg_input_source;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    // adds time in the cutlist
    system("sudo -u $ezrecorder_username $ffmpeg_script_start $asset $ffmpeg_input_source >> $ffmpeg_recorder_logs 2>&1 &");

    //TODO: The status has probably not changed yet since the last process in started in background
    //update recording status
    $status = capture_ffmpeg_status_get();
    if ($status == "open") {
        capture_ffmpeg_status_set('recording');
        $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": User started recording", array("module",$module_name));
    } else {
        capture_ffmpeg_status_set("error");
        error_last_message("capture_start: can't start recording because current status: $status");
        $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Recording could not be started. Status: $status", array("module",$module_name));
        return false;
    }

    $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Status at function end: $status", array("module",$module_name));

    return true;
}

/**
 * @implements
 * Pauses the current recording
 */
function capture_ffmpeg_pause($asset) {
    global $logger;
    global $ffmpeg_script_cutlist;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    // get status of the current recording
    $status = capture_ffmpeg_status_get();
    if($status != 'recording') {
        error_last_message("capture_pause: can't pause recording because current status: $status");
        $logger->log(EventType::TEST, LogLevel::WARNING, __FUNCTION__.": Can't pause recording because current status: $status", array("module",$module_name));
        return false;
    }
    
    system("sudo -u $ezrecorder_username $ffmpeg_script_cutlist $asset pause >> $ffmpeg_recorder_logs 2>&1 &");
    capture_ffmpeg_status_set('paused');
    $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Recording was paused", array("module",$module_name));

    return true;
}

/**
 * @implements
 * Resumes the current paused recording
 */
function capture_ffmpeg_resume($asset) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_cutlist;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    // get status of the current recording
    $status = capture_ffmpeg_status_get();
    if($status != 'paused' && status != 'stopped') {
        error_last_message("capture_resume: can't resume recording because current status: $status");
        $logger->log(EventType::TEST, LogLevel::WARNING, __FUNCTION__.": Can't resume recording because current status: $status", array("module", $module_name));
        return false;
    }
    
    system("sudo -u $ezrecorder_username $ffmpeg_script_cutlist $asset resume >> $ffmpeg_recorder_logs 2>&1 &");
    // sets the new status of the current recording
    capture_ffmpeg_status_set('recording');
    $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Recording was resumed", array("module",$module_name));

    return true;
}

/**
 * @implements
 * Stops the current recording
 */
function capture_ffmpeg_stop(&$pid, $asset) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_cutlist;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    $tmp_dir = capture_ffmpeg_tmpdir_get($asset);

    // get status of the current recording
    $status = capture_ffmpeg_status_get();
    if ($status == 'recording' || $status == "paused") {
        // pauses the current recording (while user chooses the way to publish the record)
        system("sudo -u $ezrecorder_username $ffmpeg_script_cutlist $asset stop >> $ffmpeg_recorder_logs 2>&1 & echo $! > $tmp_dir/pid");
        $pid = file_get_contents("$tmp_dir/pid");

        // set the new status for the current recording
        capture_ffmpeg_status_set('stopped');
        $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Recording was stopped by user", array("module",$module_name));
    } else {
        error_last_message("capture_stop: can't stop recording because current status: $status");
        $logger->log(EventType::TEST, LogLevel::WARNING, __FUNCTION__.": Can't stop recording because current status: $status", array("module",$module_name));
        return false;
    }

    return true;
}

/**
 * @implements
 * Ends the current recording and saves it as an archive
 */
function capture_ffmpeg_cancel($asset) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_cancel;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    // get status of the current recording
    $status = capture_ffmpeg_status_get();
    if ($status == 'recording' || $status == 'stopped' || $status == 'paused' || $status == 'open' || $status == '') {
        // qtbcancel cancels the current recording, saves it in archive dir and stops the monitoring
        $cmd = 'sudo -u ' . $ezrecorder_username . ' ' . $ffmpeg_script_cancel . ' ' . $asset . ' >> ' . $ffmpeg_recorder_logs . ' 2>&1 &';
        log_append('recording', "launching command: $cmd");
        $fpart = exec($cmd, $outputarray, $errorcode);
        $post_array = capture_ffmpeg_info_get('streaming', $asset);
        if ($post_array !== false) {
            // streaming enabled
            global $ezcast_submit_url;
            $post_array['action'] = 'streaming_close';
            $res = server_request_send($ezcast_submit_url, $post_array);
            if (strpos($res, 'error') !== false) {
                $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": An error occured while starting streaming on the server", array("module",$module_name));
            }
        }
        capture_ffmpeg_recstatus_set('');
        $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Recording was cancelled", array("module",$module_name));
    } else {
        error_last_message("capture_cancel: can't cancel recording because current status: " . $status);
        $logger->log(EventType::TEST, LogLevel::WARNING, __FUNCTION__.": Can't cancel recording because of current status: $status", array("module",$module_name));
        return false;
    }

    return true;
}

/**
 * @implements
 * Processes the record before sending it to the server
 * @param assoc_array $metadata_assoc metadata relative to current recording
 */
function capture_ffmpeg_process($meta_assoc, &$pid) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_stop;
    global $ffmpeg_recorder_logs;
    global $ffmpeg_processing_tool;
    global $ffmpeg_processing_tools;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    $asset = $meta_assoc['record_date'] . '_' . $meta_assoc['course_name'];
    $tmp_dir = capture_ffmpeg_tmpdir_get($asset);

    if (!in_array($ffmpeg_processing_tool, $ffmpeg_processing_tools))
        $ffmpeg_processing_tool = $ffmpeg_processing_tools[0];

    // saves recording metadata in xml file
    assoc_array2xml_file($meta_assoc, "$tmp_dir/_metadata.xml");

    $status = capture_ffmpeg_status_get();
    if ($status != 'recording' && $status != 'open') {
        // saves recording in processing dir and processes it
        // launched in background to save time
        $cmd = 'sudo -u ' . $ezrecorder_username . ' ' . $ffmpeg_script_stop . ' ' . $meta_assoc['course_name'] . ' ' . $meta_assoc['record_date'] . ' ' . $ffmpeg_processing_tool . ' >> ' . $ffmpeg_recorder_logs . ' 2>&1  & echo $! > ' . $tmp_dir . '/pid';
        log_append('recording', "launching command: $cmd");
        // returns the process id of the background task
        system($cmd);
        $pid = file_get_contents("$tmp_dir/pid");

        $post_array = capture_ffmpeg_info_get('streaming', $asset);
        if ($post_array !== false) {
            global $ezcast_submit_url;
            $post_array['action'] = 'streaming_close';
            $res = server_request_send($ezcast_submit_url, $post_array);
            if (strpos($res, 'error') !== false) {
                $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": An error occured while starting streaming on the server", array("module",$module_name));
            }
        }

        //update (clear) status
        capture_ffmpeg_status_set('');
        capture_ffmpeg_recstatus_set('');
    } else {
        error_last_message("capture_stop: can't start recording process because of current status: $status");
        $logger->log(EventType::TEST, LogLevel::ERROR, __FUNCTION__.": Can't start recording process because of current status: $status", array("module",$module_name));
        return false;
    }

    //should be saved in Movies/local_processing/<date+hour>/
    //combine cam and slide:
    //one need to activate at on the mac:
    //	vi /System/Library/LaunchDaemons/com.apple.atrun.plisto
    //	change Disabled tag value from <true /> to <false/>
    //   	launchctl unload -F /System/Library/LaunchDaemons/com.apple.atrun.plist
    //  	launchctl load -F /System/Library/LaunchDaemons/com.apple.atrun.plist

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": Processing successfully started", array("module",$module_name));
    return true;
}

/**
 * @implements
 * Finalizes the recording after it has been uploaded to the server.
 * The finalization consists in archiving video files in a specific dir
 * and removing all temp files used during the session.
 * @global type $ffmpeg_script_qtbfinalize
 * @global type $ffmpeg_recorder_logs
 * @global type $dir_date_format
 */
function capture_ffmpeg_finalize($asset) {
    global $logger;
    global $module_name;
    global $ffmpeg_script_finalize;
    global $ffmpeg_recorder_logs;
    global $ezrecorder_username;

    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": called", array("module",$module_name));
    
    $tmp_dir = capture_ffmpeg_tmpdir_get($asset);

    // retrieves course_name and record_date
    $meta_assoc = xml_file2assoc_array("$tmp_dir/_metadata.xml");

    // launches finalization bash script
    $cmd = 'sudo -u ' . $ezrecorder_username . ' ' . $ffmpeg_script_finalize . ' ' . $meta_assoc['course_name'] . " " . $meta_assoc['record_date'] . ' >> ' . $ffmpeg_recorder_logs . ' 2>&1  & echo $!';
    log_append("finalizing: execute cmd '$cmd'");
    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": Executing cmd: '$cmd'", array("module",$module_name));
    exec($cmd);
    $logger->log(EventType::TEST, LogLevel::INFO, __FUNCTION__.": Finished finalization", array("module",$module_name));
}

/**
 * @implements
 * Returns an associative array containing information required for given action
 * @global type $ezrecorder_ip
 * @global type $ffmpeg_download_protocol
 * @global type $ezrecorder_username
 * @return type
 */
function capture_ffmpeg_info_get($action, $asset = '') {
    global $logger;
    global $module_name;
    global $ezrecorder_ip;
    global $ffmpeg_download_protocol;
    global $ffmpeg_streaming_protocol;
    global $ffmpeg_streaming_quality;
    global $ezrecorder_username;
    global $ffmpeg_upload_dir;
    global $classroom;
    global $cam_module;

    switch ($action) {
        case 'download':
            $tmp_dir = capture_ffmpeg_tmpdir_get($asset);
            $meta_assoc = xml_file2assoc_array("$tmp_dir/_metadata.xml");

            // rsync requires ssh protocol is set (key sharing) on the remote server
            $download_info_array = array("ip" => $ezrecorder_ip,
                "protocol" => $ffmpeg_download_protocol,
                "username" => $ezrecorder_username,
                "filename" => $ffmpeg_upload_dir . $meta_assoc['record_date'] . "_" . $meta_assoc['course_name'] . "/cam.mov");
            return $download_info_array;
        case 'streaming':
            include_once 'info.php';
            $tmp_dir = capture_ffmpeg_tmpdir_get($asset);
            if ($ffmpeg_streaming_quality == 'none')
                return false;
            
            $meta_assoc = xml_file2assoc_array("$tmp_dir/_metadata.xml");
            $module_type = (($cam_module == $module_name) ? 'cam' : 'slide');
            // streaming is disabled if it has not been enabled by user
            // or if the module type is not of record type
            if ($meta_assoc['streaming'] === 'false' || ($meta_assoc['record_type'] !== 'camslide' && $meta_assoc['record_type'] != $module_type))
                return false;
            
            $streaming_info_array = array(
                "ip" => $ezrecorder_ip,
                "protocol" => $ffmpeg_streaming_protocol,
                "album" => $meta_assoc['course_name'],
                "asset" => $meta_assoc['record_date'],
                "record_type" => $meta_assoc['record_type'],
                "module_type" => $module_type,
                "module_quality" => $ffmpeg_streaming_quality,
                "classroom" => $classroom,
                "netid" => $meta_assoc['netid'],
                "author" => $meta_assoc['author'],
                "title" => $meta_assoc['title']);
            
            return $streaming_info_array;
    }
}

/**
 * @implements
 * Creates a thumbnail picture
 * @return string the contents of the image to display
 */
function capture_ffmpeg_thumbnail() {
    global $ffmpeg_basedir;
    global $ffmpeg_capture_file;

    // Camera screenshot
    $diff = time() - filemtime($ffmpeg_capture_file);
    if (!file_exists($ffmpeg_capture_file) || ($diff > 3)) {
        //if no image or image is old get a new screencapture
        if ((time() - filemtime("$ffmpeg_basedir/var/pic_new.jpg") > 3)) {
            //print "could not take a screencapture";
            copy("./nopic.jpg", $ffmpeg_capture_file);
        } else {
            //copy screencapture to actual snap
            $status = capture_ffmpeg_status_get();
            if ($status == 'recording') {
                $status = capture_ffmpeg_recstatus_get();
            }
            rename("$ffmpeg_basedir/var/pic_new.jpg", $ffmpeg_capture_file);
        }
    }
    return file_get_contents($ffmpeg_capture_file);
}

/**
 * @implements
 * Returns the current status of the recording
 * Status may be "open", "recording", "paused", "stopped", "error"
 */
function capture_ffmpeg_status_get() {
    global $ffmpeg_status_file;

    if (!file_exists($ffmpeg_status_file))
        return '';

    return trim(file_get_contents($ffmpeg_status_file));
}

/**
 * @implements
 * Returns an array containing the features offered by the module
 * @global type $ffmpeg_features
 * @return type
 */
function capture_ffmpeg_features_get() {
    global $ffmpeg_features;
    global $ffmpeg_streaming_quality;

    if ($ffmpeg_streaming_quality == 'none') {
        if ($index = array_search('streaming', $ffmpeg_features) !== false) {
            unset($ffmpeg_features[$index]);
        }
    }

    return $ffmpeg_features;
}

/**
 * @implements
 * Defines the status of the current recording
 */
function capture_ffmpeg_status_set($status) {
    global $logger;
    global $module_name;
    global $ffmpeg_status_file;

    file_put_contents($ffmpeg_status_file, $status);
    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": Status set to ".$status, array("module",$module_name));
}

/**
 * returns the real status of the current recording
 * @global type $ffmpeg_movie_name
 * @return string
 */
function capture_ffmpeg_recstatus_get() {
    global $ffmpeg_recstatus_file;

    if (!file_exists($ffmpeg_recstatus_file))
        return '';

    return trim(file_get_contents($ffmpeg_recstatus_file));
}

/**
 * sets the real status of the current recording
 * @global type $ffmpeg_status_file
 * @param type $status
 */
function capture_ffmpeg_recstatus_set($status) {
    global $logger;
    global $module_name;
    global $ffmpeg_recstatus_file;

    file_put_contents($ffmpeg_recstatus_file, $status);
    $logger->log(EventType::TEST, LogLevel::DEBUG, __FUNCTION__.": rectatus set to: '".$status . "'. Caller: " . debug_backtrace()[1]['function'], array("module",$module_name));
}

function capture_ffmpeg_tmpdir_get($asset) {
    global $ffmpeg_basedir;
    static $tmp_dir;

    $tmp_dir = $ffmpeg_basedir . '/var/' . $asset;
    if (!file_exists($tmp_dir))
        mkdir($tmp_dir, 0777, true);

    return $tmp_dir;
}

?>
