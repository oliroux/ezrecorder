<?php

// used by cli_monitoring.php when a recording has timed out
$remoteffmpeg_basedir = __DIR__.'/';
$remoteffmpeg_recorddir = '~/Movies/';
$remoteffmpeg_movie_name = "ffmpegmovie";
$remoteffmpeg_monitoring_file = $remoteffmpeg_basedir . 'var/_monitoring_pid';
$remoteffmpeg_rec_status_file = $remoteffmpeg_basedir . 'var/rec_status';
$remoteffmpeg_status_file = $remoteffmpeg_basedir . 'var/status';
$remoteffmpeg_streaming_info = $remoteffmpeg_basedir . 'var/streaming.xml';
$remoteffmpeg_cli_streaming = $remoteffmpeg_basedir . 'cli_streaming_content_send.php';
$remoteffmpeg_pid_file = $remoteffmpeg_basedir.'var/ffmpeg_pid';
$process_result_filename = "process_result.txt";
$ffmpeg_movie_name = 'ffmpegmovie';

$remoteffmpeg_input_source = "AV.io"; // specifies the input source (IntensityShuttle | UltraStudioMiniRecorder | ...)