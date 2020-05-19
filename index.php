<?php

define("CI_SNAPSHOTS", true);

require("lib/init.php");

if( isset($_GET['dl']) ) {
    $dl_parts = explode("_", $_GET['dl']);
    if( count($dl_parts) >= 2 ) {
        $dl_key = $dl_parts[0];
        $dl_filename = str_replace($dl_key.'_', '', $_GET['dl']);
        
        $dl_sid = CheckSnapshotExists($dl_filename, $dl_key);
        if( $dl_sid === false ) {
            ExitFileNotFound();
        }
        
        $dl_filepath = getSnapshotFilePath($dl_filename, $dl_key);
        if( !is_file($dl_filepath) ) {
            ExitFileNotFound();
        } else {
            if( !is_readable($dl_filepath) ) {
                ExitFailedRequest("Failed Request - File Read Error");
            }
            
            @set_time_limit(0);
            
            $size = @filesize($dl_filepath);
            $file = @fopen($dl_filepath, "rb");
            if( $file !== false && $size !== false ) {
                UpdateSnapshotDownloads($dl_sid);
                AddDownloadLogRecord($dl_filepath);
                
                header('Content-Type: application/octet-stream');
                header("Content-Length: ${size}");
                header("Content-Transfer-Encoding: Binary");
                header("Content-Encoding: Binary");
                header("Content-Disposition: attachment; filename=\"". $dl_filename ."\"");
                header("Pragma: public");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                
                while(!feof($file)) {
                    print(@fread($file, 8192));
                    @ob_flush();
                    @flush();
                }
            } else {
                ExitFailedRequest();
            }
        }
    } else {
        ExitFileNotFound();
    }
    
} 
else {
    $page = file_get_contents('tpl/index.tpl.html');
    $totalSizeListedStr = "";
    
    try {
        $stmt = $dbh->prepare("SELECT `file_name`, `file_key`, `time_created`, `time_expires`, `max_downloads`, `num_downloads` 
                               FROM `Snapshots` 
                               WHERE `time_expires` > NOW()
                               ORDER BY `time_created` DESC");
        $stmt->execute();
        
        $elements = '<li class="hinfo"> Name <span class="fileinfo"><span class="filetime">Created</span><span class="filesize">Size</span>'.
                    '<span class="filetime">Expires</span><span class="filesize">DL #</span><span class="filegitlinks">Github</span></span></li>';
        $latest_branch_snaps = array(
            'windows' => null,
            'linux' => null, 
            'macos' => null
        );
        $branch_names = ['pull-request', 'branch']; // defaults needed for client-side js.
        $branch_options = '';
        
        $totalSizeListed = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if( $row['max_downloads'] > 0 && $row['num_downloads'] >= $row['max_downloads'] ) {
                continue;
            }
            
            $url = getSnapshotURL($row['file_name'], $row['file_key']);
            $filepath = getSnapshotFilePath($row['file_name'], $row['file_key']);
            
            $filesizebytes = 0;
            if( !is_file($filepath) ) {
                continue;
            } else {
                $filesizebytes = filesize($filepath);
                $totalSizeListed = $totalSizeListed + $filesizebytes;
            }
            $date = DateTime::createFromFormat("Y-m-d H:i:s", $row['time_created']);
            $exdate = DateTime::createFromFormat("Y-m-d H:i:s", $row['time_expires']);
            
            $datetime = $date->format('Y-m-d H:i');
            $datetime8601 = $date->format('c');
            $exdatetime = $exdate->format('Y-m-d H:i');
            $exdatetime8601 = $exdate->format('c');
            $filesize = human_filesize($filesizebytes);
            $fname = $row['file_name'];
            
            // should probably start pushing explicit data to the DB instead of doing this regex stuff
            // but that would be more complicated in CI...  
            preg_match('/(?:-PR([0-9]+))?-([a-f0-9]{5,9})[\.-]{1}/i', $fname, $m);
            preg_match('/\d+(?:\.\d+)+-(ptb)-(?:PR\d+-|\d+-)?/i', $fname, $bm);
            
            // Build? Branch?  The regex pulls exact match but this code is extensible.
            $branch_class = '';
            if( count($bm) == 2 ) {
                if( !empty($bm[1]) ) {
                    if( !in_array($bm[1], $branch_names) ) {
                        $branch_names[] = $bm[1];
                        $branch_options .= '<option value="'. $bm[1] .'">'. $bm[1] .' Only</option>';
                    }
                    $branch_class = $bm[1];
                }
            }
            
            $source_class = 'branch '. $branch_class;
            $gitLinks = $PR_ID = $Commit_ID = "";
            if( count($m) == 3 ) {
                if( !empty($m[1]) ) {
                    $source_class = 'pull-request '. $branch_class;
                    $PR_ID = '<a href="https://github.com/Mudlet/Mudlet/pull/'. $m[1] .'" title="View Pull Request on Github.com"><i class="far fa-code-merge"></i></a>';
                }
                if( !empty($m[2]) ) {
                    $Commit_ID = '<a href="https://github.com/Mudlet/Mudlet/commit/'. $m[2] .'" title="View Commit on Github.com"><i class="far fa-code-commit"></i></a>';
                }
            }
            elseif( count($m) == 2 ) {
                if( !empty($m[2]) ) {
                    $Commit_ID = '<a href="https://github.com/Mudlet/Mudlet/commit/'. $m[2] .'" title="View Commit on Github.com"><i class="far fa-code-commit"></i></a>';
                }
            }
            if( $Commit_ID != "" || $PR_ID != "" ) {
                $gitLinks = '<span class="filegitlinks">'. $PR_ID . $Commit_ID .'</span>';
            }
            
            $platform_icon = '<i class="fas fa-file-archive platform-icon"></i>';
            $platform_type = 'unknown';
            $lowerFilename = strtolower($row['file_name']);
            if ( false !== strpos($lowerFilename, 'windows') || 
                 false !== strpos($lowerFilename, 'exe')) 
            {
                $platform_icon = '<i class="fab fa-windows platform-icon"></i>';
                $platform_type = 'windows';
            }
            if ( false !== strpos($lowerFilename, 'linux') || 
                 false !== strpos($lowerFilename, 'appimage')) 
            {
                $platform_icon = '<i class="fab fa-linux platform-icon"></i>';
                $platform_type = 'linux';
            }
            if ( false !== strpos($lowerFilename, 'dmg') ) 
            {
                $platform_icon = '<i class="fab fa-apple platform-icon"></i>';
                $platform_type = 'macos';
            }
            
            $item_classes = implode(' ', array($platform_type, $source_class));
            
            $item_link = '<a class="filename" href="'.$url.'" rel="nofollow">'.$platform_icon . $fname.'</a>';
            
            $item = $item_link . '<span class="fileinfo">'.
                    '<span class="filetime" data-isotime="'. $datetime8601 .'">'. $datetime . 
                    '</span><span class="filesize">'. $filesize .'</span>'.
                    '<span class="filetime" data-isotime="'. $exdatetime8601 .'">'. $exdatetime .'</span>' .
                    '<span class="filedls">'. $row['num_downloads'] .'</span>'. $gitLinks .'</span>';
            
            $item = "<li class=\"filelist-item {$item_classes}\">{$item}</li>\n";
            
            $elements .= $item;
            
            
            // NOTE: This mess allows extensible filtering of the "Latest" list.
            //$inputSource = '';
            //if ( isset($_GET['source']) ) {
            //    $inputSource = strval($_GET['source']);
            //    if (stripos('all,branch,pull-request', $inputSource) !== false ) {
            //        $inputSource = '';
            //    }
            //}
            //if ( strpos($source_class, 'branch') !== false && (empty($inputSource) || (strpos($source_class, $inputSource) !== false && !empty($inputSource))) ) {
            if ( strpos($source_class, 'branch') !== false && strpos($source_class, 'ptb') !== false ) {
                if ($latest_branch_snaps['windows'] == null && $platform_type == 'windows') {
                    $latest_branch_snaps['windows'] = '<span class="windows"><label>Windows:</label> '.$item_link.'</span>';
                }
                
                if ($latest_branch_snaps['linux'] == null && $platform_type == 'linux') {
                    $latest_branch_snaps['linux'] = '<span class="linux"><label>Linux:</label> '.$item_link.'</span>';
                }
                
                if ($latest_branch_snaps['macos'] == null && $platform_type == 'macos') {
                    $latest_branch_snaps['macos'] = '<span class="macos"><label>Mac OS X:</label> '.$item_link.'</span>';
                }
            }
        }
        $stmt = null;
        
        $content = '<ul class="filelist">'. $elements ."</ul>\n";
        
        $latest_branch_content = implode('<br>', $latest_branch_snaps);
        
        $totalSizeListedStr = human_filesize( $totalSizeListed );
    } catch (PDOException $e) {
        $content = "Error while fetching Snapshot list!<br/>\n";
        $latest_branch_content = $content;
    }
    
    $page = str_replace('{branch_names_opts}', $branch_options, $page);
    $page = str_replace('{branch_names_js}', json_encode($branch_names), $page);
    $page = str_replace('{pg_size_listed}', $totalSizeListedStr, $page);
    $page = str_replace('{SITE_URL}', SITE_URL, $page);
    $page = str_replace('{pg_timezone}', date_default_timezone_get(), $page);
    $page = str_replace('{latest_branch_snapshots}', $latest_branch_content, $page);
    $page = str_replace('{snapshot_list}', $content, $page);
    echo($page);
}

