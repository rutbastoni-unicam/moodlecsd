<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Locallib.
 *
 * @package mod_webgl
 * @copyright  2020 Brain station 23 ltd <>  {@link https://brainstation-23.com/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once('BlobStorage.php');
require_once('mod_form.php');

/**
 * Extracts the imported zip contents.
 * Push to Azure BLOB storage.
 * @param stdClass $webgl
 * @param string $zipfilepath
 * @return array List of imported files.
 * @throws moodle_exception
 */
function webgl_import_extract_upload_contents(stdClass $webgl, string $zipfilepath): array {

    $importtempdir = make_request_directory('webglcontentimport' . microtime(false));

    $zip = new zip_packer();

    $filelist = $zip->extract_to_pathname($zipfilepath, $importtempdir);

    $dirname = array_key_first($filelist);

    if (!is_dir($importtempdir . DIRECTORY_SEPARATOR . $dirname)) {

        $dirnamearr = explode('/', $dirname);

        $dirname = $dirnamearr[0] . DIRECTORY_SEPARATOR;

    }

    if (!is_dir($importtempdir . DIRECTORY_SEPARATOR . $dirname)) {
        // Missing required file.
        throw new moodle_exception('invalidcontent', 'mod_webgl');
    }

    //--Rut - 27/11/2024 - fix for new webgl builds (index not in folder 'Build')
    $indexfile = false;
    foreach ($filelist as $filename => $value){
        if(strpos($filename, 'index.html') !== false) {
            $indexfile = $filename;
            break;
        }
    }
//    $indexfile = $dirname . 'index.html';

//    if (!in_array($indexfile, $filelist)) {
    if (!$indexfile) {
        // Missing required file.
        throw new moodle_exception('errorimport', 'mod_webgl');
    }

    $indexdir = dirname($indexfile);
    $indexdirname = $indexdir === '.' ? '' : $indexdir;
    if (!empty($indexdirname) && !str_ends_with($indexdirname, '/')) {
        $indexdirname .= '/';
    }

    // Upload to S3.
    if ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_S3) {
        $bucket = get_config('webgl', 'bucket_name');
        list($endpoint, $foldername) = webgl_s3_upload($webgl, $bucket, $filelist, $importtempdir);

        return [
            'index' => "https://$bucket." . $endpoint . '/' . "$foldername/" . array_key_first($filelist) . 'index.html'
        ];
    }
    elseif ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_LOCAL_DISK){
        $context = context_module::instance($webgl->coursemodule);
        $zip = new zip_packer();
        $extractedfiles = $zip->extract_to_storage($zipfilepath,$context->id,'mod_webgl','content', $webgl->id,'/');
        if (!$extractedfiles){
            throw new moodle_exception('invalidcontent','mod_webgl');
        }
//        $moodle_url = moodle_url::make_pluginfile_url($context->id,'mod_webgl','content',$webgl->id,'/'.$dirname,'index.html');
        $moodle_url = moodle_url::make_pluginfile_url($context->id,'mod_webgl','content',$webgl->id,'/'.$indexdirname,'index.html');
        return [
            'index' => $moodle_url->out()
        ];
    }
    else {
        // Upload to Azure Blob storage.
        $blobclient = webgl_get_connection($webgl->account_name, $webgl->account_key);

        foreach ($filelist as $filename => $value):

            $cfile = $importtempdir . DIRECTORY_SEPARATOR . $filename;

            if (!is_dir($cfile)) {

                $replacewith = webgl_cloud_storage_webgl_content_prefix($webgl);

                $filename = webgl_str_replace_first($filename, '/', $replacewith);

                $contetnttype = mime_content_type($cfile);

                $content = fopen($cfile, "r");

                webgl_upload_blob($blobclient, $filename, $content, $contetnttype, $webgl->container_name);

                if (is_resource($content)) {

                    fclose($content);

                }
            }

        endforeach;

        return webgl_list_blobs($blobclient, $webgl);
    }

}

/**
 * Upload to s3.
 * @param stdClass $webgl
 * @param string $bucket
 * @param array $filelist
 * @param string $importtempdir
 * @param string $replacewith
 * @return mixed
 * @throws dml_exception
 */
function webgl_s3_upload(stdClass $webgl, string $bucket, $filelist, $importtempdir) {
    list($s3, $endpoint) = webgl_get_s3_instance($webgl, false);

    // Folder name for the webgl content.
    $foldername = webgl_cloud_storage_webgl_content_prefix($webgl);
    foreach ($filelist as $filename => $value):

        $cfile = $importtempdir . DIRECTORY_SEPARATOR . $filename;

        if (!is_dir($cfile)) {
            $s3->putObject($s3->inputFile($cfile), $bucket, $foldername . '/' . $filename, S3::ACL_PUBLIC_READ);
        }
    endforeach;
    return [$endpoint, $foldername];
}

/**
 * Upload zip file.
 *
 * @param stdClass $webgl
 * @param moodleform_mod $mform
 * @param string $elname
 * @param string $res
 * @throws dml_exception
 */
function webgl_upload_zip_file($webgl, $mform, $elname, $res) {
    if ($webgl->store_zip_file) {

        if ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_AZURE) {

            $zipcontent = $mform->get_file_content($elname);

            webgl_import_zip_contents($webgl, $zipcontent);

        } elseif ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_S3) {
            list($s3, $endpoint) = webgl_get_s3_instance($webgl);

            $bucket = get_config('webgl', 'bucket_name');
            $filename = $webgl->webgl_file;
            $foldername = webgl_cloud_storage_webgl_content_prefix($webgl);
            $s3->putObject($s3->inputFile($res), $bucket, $foldername . '/' . $filename, S3::ACL_PUBLIC_READ, [
                'Content-Type' => "application/octet-stream",
            ]);

        }else{
            //TODO: Implement Moodle file system zip file import
        }
    }
}

/**
 * Get s3 instance.
 *
 * @param stdClass $webgl
 * @param bool $exceptionenabled
 * @return array
 * @throws dml_exception
 */
function webgl_get_s3_instance(stdClass $webgl, $exceptionenabled = true) {
    $accesskey = empty($webgl->access_key) ? $webgl->access_key : get_config('webgl', 'access_key');

    $secretkey = empty($webgl->secret_key) ? $webgl->secret_key : get_config('webgl', 'secret_key');

    $endpoint = empty($webgl->endpoint) ? $webgl->endpoint : get_config('webgl', 'endpoint');

    $s3 = new S3($accesskey, $secretkey, false, $endpoint);

    $s3->setExceptions($exceptionenabled);

    // Port of curl::__construct().
    if (!empty($CFG->proxyhost)) {

        if (empty($CFG->proxyport)) {

            $proxyhost = $CFG->proxyhost;

        } else {

            $proxyhost = $CFG->proxyhost . ':' . $CFG->proxyport;
        }

        $proxytype = CURLPROXY_HTTP;

        $proxyuser = null;

        $proxypass = null;

        if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {

            $proxyuser = $CFG->proxyuser;

            $proxypass = $CFG->proxypassword;

        }

        if (!empty($CFG->proxytype) && $CFG->proxytype == 'SOCKS5') {

            $proxytype = CURLPROXY_SOCKS5;

        }

        $s3->setProxy($proxyhost, $proxyuser, $proxypass, $proxytype);
    }
    return [$s3, $endpoint];
}

/**
 * Make prefix webgl blob file name.
 *
 * @param stdClass $webgl
 * @return string
 */
function webgl_cloud_storage_webgl_content_prefix(stdClass $webgl) {
    $hostname = gethostname();

    $bucket = "$hostname-course-$webgl->course" . "-module-id-$webgl->id";

    $bucket = strtolower($bucket);

    $bucket = str_replace('_', '-', $bucket);

    $bucket = str_replace('.', '-', $bucket);

    $bucketlength = strlen($bucket);

    if ($bucketlength < 3) {

        $bucket .= random_string(10);

    } else if ($bucketlength > 63) {

        $excitedlength = $bucketlength - 63;

        $bucket = substr_replace($bucket, "", 20, $excitedlength);

    }

    return $bucket;
}

/**
 * Create s3 bucket.
 *
 * @param stdClass $webgl
 * @param string $bucket
 * @param string $visibility
 * @param string $location
 * @return array
 * @throws dml_exception
 */
function webgl_s3_create_bucket(stdClass $webgl, string $bucket, string $visibility = S3::ACL_PRIVATE,
                                string   $location = mod_webgl_mod_form::STORAGE_ENGINE_S3_DEFAULT_LOCATION) {
    list($s3, $endpoint) = webgl_get_s3_instance($webgl, false);

    $bucketobjectremoved = webgl_make_empty_s3_bucket($s3, $bucket);

    if (!$bucketobjectremoved) {

        $s3->putBucket($bucket, $visibility, $location);

    }

    return [$s3, $endpoint];
}

/**
 * Delete s3 Bucket.
 *
 * @param stdClass $webgl
 * @return S3
 * @throws dml_exception
 */
function webgl_delete_s3_bucket(stdClass $webgl) {
    list($s3, $endpoint) = webgl_get_s3_instance($webgl, false);

    $bucket = webgl_cloud_storage_webgl_content_prefix($webgl);

    webgl_make_empty_s3_bucket($s3, $bucket);

    return $s3->deleteBucket($bucket);
}

/**
 * Delete object from s3 bucket.
 *
 * @param stdClass $webgl
 * @return boolean
 * @throws dml_exception
 */
function webgl_delete_from_s3(stdClass $webgl) {
    list($s3, $endpoint) = webgl_get_s3_instance($webgl, false);
    $bucket = get_config('webgl', 'bucket_name');
    $objects = $s3->getBucket($bucket);

    $foldername = webgl_cloud_storage_webgl_content_prefix($webgl);
    //var_dump($objects, $foldername);
    if (is_array($objects)) {
        foreach ($objects as $key => $object):
            $dirname = explode('/', $key);
            if($dirname[0] == $foldername) {
                // Delete folder from the bucket.
                $s3->deleteObject($bucket, $key);
            }
        endforeach;
        return true;
    }
    return false;
}
/**
 * Make empty s3 bucket.
 *
 * @param S3 $s3
 * @param string $bucket
 * @return bool
 */
function webgl_make_empty_s3_bucket(S3 $s3, string $bucket) {

    $objects = $s3->getBucket($bucket);

    if (is_array($objects)) {
        foreach ($objects as $key => $object):

            $s3->deleteObject($bucket, $key);

        endforeach;

        // Bucket exists.
        return true;
    }

    return false;

}

/**
 * Extracts the imported zip contents.
 * Push to Azure BLOB storage.
 * @param stdClass $webgl
 * @param string $content
 * @return void
 */
function webgl_import_zip_contents(stdClass $webgl, string $content): void {
    $blobclient = webgl_get_connection($webgl->account_name, $webgl->account_key);

    $prefix = webgl_cloud_storage_webgl_content_prefix($webgl);

    $filename = $prefix . DIRECTORY_SEPARATOR . $webgl->webgl_file;

    $contetnttype = "application/octet-stream";

    webgl_upload_blob($blobclient, $filename, $content, $contetnttype, $webgl->container_name);
}

/**
 * Delete from File System API.
 *
 * @param stdClass $webgl
 * @return void
 * @throws coding_exception
 * @throws moodle_exception
 */
function webgl_delete_from_file_system(stdClass $webgl): void {
    $context = context_module::instance($webgl->coursemodule);
    // Get file
    $fs = get_file_storage();
    //$file = $fs->get_file($context->id,'mod_webgl','content', $webgl->id,'/'.$dirname,'index.html');
    $files = $fs->get_area_files($context->id, 'mod_webgl', 'content', $webgl->id, 'id ASC');
    foreach ($files as $file) {
        $file->delete();
    }

    // Delete it if it exists
//    if ($file) {
//        $file->delete();
//    }
}


/**
 * Download container blobs.
 *
 * @param stdClass $webgl
 * @return void
 * @throws coding_exception
 * @throws moodle_exception
 */
function webgl_download_container_blobs(stdClass $webgl): void {
    $blobclient = webgl_get_connection($webgl->account_name, $webgl->account_key);
    webgl_download_blobs($blobclient, $webgl);
}

/**
 * Delete azure blob container content.
 * @param stdClass $webgl
 */
function webgl_delete_container_blobs(stdClass $webgl) {
    $blobclient = webgl_get_connection($webgl->account_name, $webgl->account_key);
    webgl_delete_blobs($blobclient, $webgl);
}

/**
 * Index file url.
 *
 * @param stdClass $webgl
 * @param array $blobdatadetails
 * @return stdClass
 */
function webgl_index_file_url($webgl, $blobdatadetails) {
    if ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_S3) {
        $webgl->index_file_url = $blobdatadetails['index'];
    }elseif ($webgl->storage_engine == mod_webgl_mod_form::STORAGE_ENGINE_LOCAL_DISK){
        $webgl->index_file_url = $blobdatadetails['index'];
    } else {
        $webgl->index_file_url = $blobdatadetails[$blobdatadetails[BS_WEBGL_INDEX]];
    }
    return $webgl;
}

/**
 * String replace first.
 *
 * @param string $haystack
 * @param string $needle
 * @param string $replace
 * @return string|string[]
 */
function webgl_str_replace_first($haystack, $needle, $replace) {
    $pos = strpos($haystack, $needle);
    if ($pos !== false) {
        return substr_replace($haystack, $replace, 0, $pos);
    }
}

/**
 * Activity navigation.
 *
 * @param moodle_page $PAGE
 * @return string
 * @throws coding_exception
 * @throws moodle_exception
 */
function activity_navigation($PAGE) {
    global $CFG;
    // First we should check if we want to add navigation.
    $context = $PAGE->context;

    // Get a list of all the activities in the course.
    $course = $PAGE->cm->get_course();
    $modules = get_fast_modinfo($course->id)->get_cms();

    $section = 1;

    // Put the modules into an array in order by the position they are shown in the course.
    $mods = [];
    $activitylist = [];
    foreach ($modules as $module) {
        // Only add activities the user can access, aren't in stealth mode and have a url (eg. mod_label does not).
        if (!$module->uservisible || $module->is_stealth() || empty($module->url)) {
            continue;
        }
        $mods[$module->id] = $module;

        // No need to add the current module to the list for the activity dropdown menu.
        if ($module->id == $PAGE->cm->id) {

            $curentmodsection = $module->get_section_info();
            $section = $curentmodsection;
            continue;
        }
        // Module name.
        $modname = $module->get_formatted_name();
        // Display the hidden text if necessary.
        if (!$module->visible) {
            $modname .= ' ' . get_string('hiddenwithbrackets');
        }
        // Module URL.
        $linkurlnext = new moodle_url($module->url, array('forceview' => 1));
        // Add module URL (as key) and name (as value) to the activity list array.
        $activitylist[$linkurlnext->out(false)] = $modname;
    }

    $nummods = count($mods);

    // If there is only one mod then do nothing.
    if ($nummods == 1) {
        return '';
    }

    // Get an array of just the course module ids used to get the cmid value based on their position in the course.
    $modids = array_keys($mods);

    // Get the position in the array of the course module we are viewing.
    $position = array_search($PAGE->cm->id, $modids);
    $sectionurl = new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $section->section]);

    $prevmod = null;
    $nextmod = null;
    $prevtotalurl = null;
    $nexttotalurl = null;

    // Check if we have a previous mod to show.
    if ($position > 0) {
        $prevmod = $mods[$modids[$position - 1]];
        $linkurlprev = new \moodle_url($prevmod->url, array('forceview' => 1));
        $linknameprev = $prevmod->get_formatted_name();
        if (!$prevmod->visible) {
            $linknameprev .= ' ' . get_string('hiddenwithbrackets');
        }
        $prevtotalurl = '<a href="' . $linkurlprev
            . '" id="prev-activity-link" class="btn btn-link btn-action text-truncate" title="'
            . $linknameprev . '">' . $linknameprev . '</a>';
    }

    // Check if we have a next mod to show.
    if ($position < ($nummods - 1)) {
        $nextmod = $mods[$modids[$position + 1]];
        $linkurlnext = new \moodle_url($nextmod->url, array('forceview' => 1));
        $linknamenext = $nextmod->get_formatted_name();
        if (!$nextmod->visible) {
            $linknamenext .= ' ' . get_string('hiddenwithbrackets');
        }
        $nexttotalurl = '<a href="' . $linkurlnext
            . '" id="next-activity-link" class="btn btn-link btn-action text-truncate" title="'
            . $linknamenext . '"> ' . $linknamenext . '</a>';
    }
    $sectioname = $section->name ?? get_string('sectionname', 'format_' . $course->format) . ' ' . $section->section;
    $sectioninfourl = $section->section > 0 ? '<a href="' . $sectionurl
        . '"   id="activity-link" class="btn btn-link btn-action text-truncate" title="'
        . $sectioname . '">' . $sectioname . '</a>' : '';

    return '<div class="course-footer-nav">
            <hr class="hr">
            <div class="row">
                <div class="col-sm-12 col-md">
                    <div class="pull-left">' . $prevtotalurl . '</div>
                </div>
                <div class="col-sm-12 col-md-2">
                    <div class="mdl-align" >' . $sectioninfourl . '</div>
                </div>
                <div class="col-sm-12 col-md">
                    <div class="pull-right">' . $nexttotalurl . '</div>
                </div>
            </div>
        </div>';
}

/**
 * Redefined from lib\filelib.php since THERE IS NO WAY TO MODIFY IT WITHOUT EDITING MOODLE CORE FILES
 * Modified despite the original version to handle headers of output of gunzipped files (frequent in some versions of Unity/Webgl builds)
 * Without correct headers, some Unity/Webgl games won't load correctly
 * TODO: find a better way to define your own api since this is based on the 4.3 Moodle version of the file API and being
 * totally redefined, it won't get bugfixes from official repository
 *
 * @param $storedfile
 * @param $lifetime
 * @param $filter
 * @param $forcedownload
 * @param array $options
 * @return void
 */
function webgl_send_stored_file($storedfile, $lifetime=null, $filter=0, $forcedownload=false, array $options=array())
{
    global $CFG, $COURSE;

    static $recursion = 0;

    if (empty($options['filename'])) {
        $filename = null;
    } else {
        $filename = $options['filename'];
    }

    if (empty($options['dontdie'])) {
        $dontdie = false;
    } else {
        $dontdie = true;
    }

    if ($lifetime === 'default' or is_null($lifetime)) {
        $lifetime = $CFG->filelifetime;
    }

    if (!empty($options['preview'])) {
        // replace the file with its preview
        $fs = get_file_storage();
        $previewfile = $fs->get_file_preview($storedfile, $options['preview']);
        if (!$previewfile) {
            // Unable to create a preview of the file, send its default mime icon instead.
            $fileicon = file_file_icon($storedfile);
            send_file($CFG->dirroot.'/pix/'.$fileicon.'.svg', basename($fileicon).'.svg');
        } else {
            // preview images have fixed cache lifetime and they ignore forced download
            // (they are generated by GD and therefore they are considered reasonably safe).
            $storedfile = $previewfile;
            $lifetime = DAYSECS;
            $filter = 0;
            $forcedownload = false;
        }
    }

    // handle external resource
    if ($storedfile && $storedfile->is_external_file() && !isset($options['sendcachedexternalfile'])) {

        // Have we been here before?
        $recursion++;
        if ($recursion > 10) {
            throw new coding_exception('Recursive file serving detected');
        }

        $storedfile->send_file($lifetime, $filter, $forcedownload, $options);
        die;
    }

    if (!$storedfile || $storedfile->is_directory()) {
        // Nothing to serve.
        if ($dontdie) {
            return;
        }
        die;
    }

    $filename = is_null($filename) ? $storedfile->get_filename() : $filename;

    // Use given MIME type if specified.
    $mimetype = $storedfile->get_mimetype();

    // Allow cross-origin requests only for Web Services.
    // This allow to receive requests done by Web Workers or webapps in different domains.
    if (WS_SERVER) {
        header('Access-Control-Allow-Origin: *');
    }

    webgl_send_file($storedfile, $filename, $lifetime, $filter, false, $forcedownload, $mimetype, $dontdie, $options);
}

/**
 * Redefined from lib\filelib.php since THERE IS NO WAY TO MODIFY IT WITHOUT EDITING MOODLE CORE FILES
 * Modified despite the original version to handle headers of output of gunzipped files (frequent in some versions of Unity/Webgl builds)
 * Without correct headers, some Unity/Webgl games won't load correctly
 * TODO: find a better way to define your own api since this is based on the 4.3 Moodle version of the file API and being
 * totally redefined, it won't get bugfixes from official repository
 *
 * @param $path
 * @param $filename
 * @param $lifetime
 * @param $filter
 * @param $pathisstring
 * @param $forcedownload
 * @param $mimetype
 * @param $dontdie
 * @param array $options
 * @return void
 * @throws coding_exception
 * @throws file_exception
 */
function webgl_send_file($path, $filename, $lifetime = null , $filter=0, $pathisstring=false, $forcedownload=false, $mimetype='',
                   $dontdie=false, array $options = array()) {
    global $CFG, $COURSE;

    if ($dontdie) {
        ignore_user_abort(true);
    }

    if ($lifetime === 'default' or is_null($lifetime)) {
        $lifetime = $CFG->filelifetime;
    }

    if (is_object($path)) {
        $pathisstring = false;
    }

    \core\session\manager::write_close(); // Unlock session during file serving.

    // Use given MIME type if specified, otherwise guess it.
    if (!$mimetype || $mimetype === 'document/unknown') {
        $mimetype = get_mimetype_for_sending($filename);
    }

    // if user is using IE, urlencode the filename so that multibyte file name will show up correctly on popup
    if (core_useragent::is_ie() || core_useragent::is_edge()) {
        $filename = rawurlencode($filename);
    }

    // Make sure we force download of SVG files, unless the module explicitly allows them (eg within SCORM content).
    // This is for security reasons (https://digi.ninja/blog/svg_xss.php).
    if (file_is_svg_image_from_mimetype($mimetype) && empty($options['dontforcesvgdownload'])) {
        $forcedownload = true;
    }

    if ($forcedownload) {
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        // If this file was requested from a form, then mark download as complete.
        \core_form\util::form_download_complete();
    } else if ($mimetype !== 'application/x-shockwave-flash') {
        // If this is an swf don't pass content-disposition with filename as this makes the flash player treat the file
        // as an upload and enforces security that may prevent the file from being loaded.

        header('Content-Disposition: inline; filename="'.$filename.'"');
    }

    if ($lifetime > 0) {
        $immutable = '';
        if (!empty($options['immutable'])) {
            $immutable = ', immutable';
            // Overwrite lifetime accordingly:
            // 90 days only - based on Moodle point release cadence being every 3 months.
            $lifetimemin = 60 * 60 * 24 * 90;
            $lifetime = max($lifetime, $lifetimemin);
        }
        $cacheability = ' public,';
        if (!empty($options['cacheability']) && ($options['cacheability'] === 'public')) {
            // This file must be cache-able by both browsers and proxies.
            $cacheability = ' public,';
        } else if (!empty($options['cacheability']) && ($options['cacheability'] === 'private')) {
            // This file must be cache-able only by browsers.
            $cacheability = ' private,';
        } else if (isloggedin() and !isguestuser()) {
            // By default, under the conditions above, this file must be cache-able only by browsers.
            $cacheability = ' private,';
        }
        $nobyteserving = false;
        header('Cache-Control:'.$cacheability.' max-age='.$lifetime.', no-transform'.$immutable);
        header('Expires: '. gmdate('D, d M Y H:i:s', time() + $lifetime) .' GMT');
        header('Pragma: ');

    } else { // Do not cache files in proxies and browsers
        $nobyteserving = true;
        if (is_https()) { // HTTPS sites - watch out for IE! KB812935 and KB316431.
            header('Cache-Control: private, max-age=10, no-transform');
            header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            header('Pragma: ');
        } else { //normal http - prevent caching at all cost
            header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0, no-transform');
            header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
            header('Pragma: no-cache');
        }
    }

    if (empty($filter)) {
        // send the contents
        if ($pathisstring) {
            webgl_readstring_accel($path, $mimetype);
        } else {
            webgl_readfile_accel($path, $mimetype, !$dontdie);
        }

    } else {
        // Try to put the file through filters
        if ($mimetype == 'text/html' || $mimetype == 'application/xhtml+xml' || file_is_svg_image_from_mimetype($mimetype)) {
            $options = new stdClass();
            $options->noclean = true;
            $options->nocache = true; // temporary workaround for MDL-5136
            if (is_object($path)) {
                $text = $path->get_content();
            } else if ($pathisstring) {
                $text = $path;
            } else {
                $text = implode('', file($path));
            }
            $output = format_text($text, FORMAT_HTML, $options, $COURSE->id);

            webgl_readstring_accel($output, $mimetype);

        } else if (($mimetype == 'text/plain') and ($filter == 1)) {
            // only filter text if filter all files is selected
            $options = new stdClass();
            $options->newlines = false;
            $options->noclean = true;
            if (is_object($path)) {
                $text = htmlentities($path->get_content(), ENT_QUOTES, 'UTF-8');
            } else if ($pathisstring) {
                $text = htmlentities($path, ENT_QUOTES, 'UTF-8');
            } else {
                $text = htmlentities(implode('', file($path)), ENT_QUOTES, 'UTF-8');
            }
            $output = '<pre>'. format_text($text, FORMAT_MOODLE, $options, $COURSE->id) .'</pre>';

            webgl_readstring_accel($output, $mimetype);

        } else {
            // send the contents
            if ($pathisstring) {
                webgl_readstring_accel($path, $mimetype);
            } else {
                webgl_readfile_accel($path, $mimetype, !$dontdie);
            }
        }
    }
    if ($dontdie) {
        return;
    }
    die; //no more chars to output!!!
}

/**
 * Redefined from lib\filelib.php since THERE IS NO WAY TO MODIFY IT WITHOUT EDITING MOODLE CORE FILES
 * Modified despite the original version to handle headers of output of gunzipped files (frequent in some versions of Unity/Webgl builds)
 * Without correct headers, some Unity/Webgl games won't load correctly
 * TODO: find a better way to define your own api since this is based on the 4.3 Moodle version of the file API and being
 * totally redefined, it won't get bugfixes from official repository
 *
 * @param $string
 * @param $mimetype
 * @param $accelerate
 * @return void
 */
function webgl_readstring_accel($string, $mimetype, $accelerate = false) {
    global $CFG;

    if ($mimetype === 'text/plain') {
        // there is no encoding specified in text files, we need something consistent
        header('Content-Type: text/plain; charset=utf-8');
    } else {
        header('Content-Type: '.$mimetype);
    }

    if($mimetype == 'application/g-zip') {
        header('Content-Encoding: gzip');
    }
    header('Last-Modified: '. gmdate('D, d M Y H:i:s', time()) .' GMT');
    header('Accept-Ranges: none');
    header('Content-Length: '.strlen($string));
    echo $string;
}

/**
 * Redefined from lib\filelib.php since THERE IS NO WAY TO MODIFY IT WITHOUT EDITING MOODLE CORE FILES
 * Modified despite the original version to handle headers of output of gunzipped files (frequent in some versions of Unity/Webgl builds)
 * Without correct headers, some Unity/Webgl games won't load correctly
 * TODO: find a better way to define your own api since this is based on the 4.3 Moodle version of the file API and being
 * totally redefined, it won't get bugfixes from official repository
 *
 * @param $file
 * @param $mimetype
 * @param $accelerate
 * @return void
 * @throws file_exception
 */
function webgl_readfile_accel($file, $mimetype, $accelerate) {
    global $CFG;

    if ($mimetype === 'text/plain') {
        // there is no encoding specified in text files, we need something consistent
        header('Content-Type: text/plain; charset=utf-8');
    } else {
        if($mimetype == 'application/g-zip') {
            $filename = $file->get_filename();
            if(strpos($filename, 'wasm') !== false) {
                header('Content-Type: application/wasm');
            } else {
                header('Content-Type: application/gzip');
            }
        } else {
            header('Content-Type: '.$mimetype);
        }
    }

    if($mimetype == 'application/g-zip') {
        header('Content-Encoding: gzip');
    }

    $lastmodified = is_object($file) ? $file->get_timemodified() : filemtime($file);
    header('Last-Modified: '. gmdate('D, d M Y H:i:s', $lastmodified) .' GMT');

    if (is_object($file)) {
        header('Etag: "' . $file->get_contenthash() . '"');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) and trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $file->get_contenthash()) {
            header('HTTP/1.1 304 Not Modified');
            return;
        }
    }

    // if etag present for stored file rely on it exclusively
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) and (empty($_SERVER['HTTP_IF_NONE_MATCH']) or !is_object($file))) {
        // get unixtime of request header; clip extra junk off first
        $since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
        if ($since && $since >= $lastmodified) {
            header('HTTP/1.1 304 Not Modified');
            return;
        }
    }

    if ($accelerate and empty($CFG->disablebyteserving) and $mimetype !== 'text/plain') {
        header('Accept-Ranges: bytes');
    } else {
        header('Accept-Ranges: none');
    }

    if ($accelerate) {
        if (is_object($file)) {
            $fs = get_file_storage();
            if ($fs->supports_xsendfile()) {
                if ($fs->xsendfile_file($file)) {
                    return;
                }
            }
        } else {
            if (!empty($CFG->xsendfile)) {
                require_once("$CFG->libdir/xsendfilelib.php");
                if (xsendfile($file)) {
                    return;
                }
            }
        }
    }

    $filesize = is_object($file) ? $file->get_filesize() : filesize($file);

    header('Last-Modified: '. gmdate('D, d M Y H:i:s', $lastmodified) .' GMT');

    if ($accelerate and empty($CFG->disablebyteserving) and $mimetype !== 'text/plain') {

        if (!empty($_SERVER['HTTP_RANGE']) and strpos($_SERVER['HTTP_RANGE'],'bytes=') !== FALSE) {
            // byteserving stuff - for acrobat reader and download accelerators
            // see: http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
            // inspired by: http://www.coneural.org/florian/papers/04_byteserving.php
            $ranges = false;
            if (preg_match_all('/(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $ranges, PREG_SET_ORDER)) {
                foreach ($ranges as $key=>$value) {
                    if ($ranges[$key][1] == '') {
                        //suffix case
                        $ranges[$key][1] = $filesize - $ranges[$key][2];
                        $ranges[$key][2] = $filesize - 1;
                    } else if ($ranges[$key][2] == '' || $ranges[$key][2] > $filesize - 1) {
                        //fix range length
                        $ranges[$key][2] = $filesize - 1;
                    }
                    if ($ranges[$key][2] != '' && $ranges[$key][2] < $ranges[$key][1]) {
                        //invalid byte-range ==> ignore header
                        $ranges = false;
                        break;
                    }
                    //prepare multipart header
                    $ranges[$key][0] =  "\r\n--".BYTESERVING_BOUNDARY."\r\nContent-Type: $mimetype\r\n";
                    $ranges[$key][0] .= "Content-Range: bytes {$ranges[$key][1]}-{$ranges[$key][2]}/$filesize\r\n\r\n";
                }
            } else {
                $ranges = false;
            }
            if ($ranges) {
                if (is_object($file)) {
                    $handle = $file->get_content_file_handle();
                    if ($handle === false) {
                        throw new file_exception('storedfilecannotreadfile', $file->get_filename());
                    }
                } else {
                    $handle = fopen($file, 'rb');
                    if ($handle === false) {
                        throw new file_exception('cannotopenfile', $file);
                    }
                }
                byteserving_send_file($handle, $mimetype, $ranges, $filesize);
            }
        }
    }

    header('Content-Length: ' . $filesize);

    if (!empty($_SERVER['REQUEST_METHOD']) and $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

    while (ob_get_level()) {
        $handlerstack = ob_list_handlers();
        $activehandler = array_pop($handlerstack);
        if ($activehandler === 'default output handler') {
            // We do not expect any content in the buffer when we are serving files.
            $buffercontents = ob_get_clean();
            if ($buffercontents !== '') {
                error_log('Non-empty default output handler buffer detected while serving the file ' . $file);
            }
        } else {
            // Some handlers such as zlib output compression may have file signature buffered - flush it.
            ob_end_flush();
        }
    }

    // send the whole file content
    if (is_object($file)) {
        $file->readfile();
    } else {
        if (readfile_allow_large($file, $filesize) === false) {
            throw new file_exception('cannotopenfile', $file);
        }
    }
}
