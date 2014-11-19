<?php
// Configuration
$DIR2RSS_SEARCH_PATTERN='/\.(txt|html?|ogg|aac|mp3|mp4|mkv|m4v|webm|wmv|mov|avi|flv|f4v)?$/i';
$DIR2RSS_PATH=$DIR2RSS_PATH?$DIR2RSS_PATH:(isset($_GET['p'])?$_GET['p']:'');
?>
<?php
function get_filesize($path){
    if(preg_match('/file:\/\//',$path)) // Is physical unix or windows path?
        return filesize(rawurldecode(substr($path,7)));
    elseif($path[0]==='/' || preg_match('/^[A-Z]:\\\\/',$path)) // Is physical unix or windows path?
        return filesize($path);
    return ''; // Don't detect length for URL's.
}
function full_to_virtual_path($fullPath){
    $docRoot=realpath($_SERVER["DOCUMENT_ROOT"]);
    $direct='';
    if(strpos($fullPath,$docRoot)===0){
        $direct=str_replace('\\','/',substr($fullPath,strlen($docRoot)));
        if($direct=='')$direct='/';
    }
    return $direct;
}
function url_encode($s){
    $s=rawurlencode($s);
    $s=str_replace('%3A',':',$s);
    $s=str_replace('%2F','/',$s);
    return $s;
}
function ensure_uri($path){ // Ensures the output is a full URI and not just a physical or partial path.
    if(!$path)
        return $path;
    if(preg_match('/file:\/\//',$path)){ // Is physical unix or windows path?
        $vPath=full_to_virtual_path(rawurldecode(substr($path,7)));
        if($vPath)
            return url_encode(get_scheme().'://'.$_SERVER['HTTP_HOST'].$vPath);
        else
            return '';
    }
    elseif($path[0]==='/' || preg_match('/^[A-Z]:\\\\/',$path)){ // Is physical unix or windows path?
        $vPath=full_to_virtual_path($path);
        if($vPath)
            return url_encode(get_scheme().'://'.$_SERVER['HTTP_HOST'].$vPath);
        else
            return '';
    }
    return $path;
}
function scandir_recursive($dir,$includeDirs=TRUE,$ignoreHidden=FALSE,$_prefix=''){
  $dir=rtrim($dir,'\\/');
  $r=array();
  $files=scandir($dir);
  sort($files,SORT_NATURAL|SORT_FLAG_CASE);
  foreach($files as $f){
    if((!$ignoreHidden||$f[0]!=='.')&&$f!=='.'&&$f!=='..')
      if(is_dir("$dir/$f")){
        $r=array_merge($r,scandir_recursive("$dir/$f",$includeDirs,$ignoreHidden,"$_prefix$f/"));
        if($includeDirs)$r[]=$_prefix.$f;
      }else $r[]=$_prefix.$f;
  }
  return $r;
}
function renderFile($relPath,$fullPath,$isHtml=FALSE,$parseContent=TRUE,$url=NULL,$attachmentPathOrUrl=NULL){
    if($parseContent){
        $content=trim(file_get_contents($fullPath));
        preg_match('/(https?|file):\/\/[^ \r\n"]*/i',$content,$matches);
        if($matches)
            $url=trim($matches[0]);
        preg_match('/(https?|file):\/\/.*\.(ogg|aac|mp3|mp4|mkv|m4v|webm|wmv|mov|avi|flv|f4v)[^ \r\n"]*/i',$content,$matches);
        if($matches)
            $attachmentPathOrUrl=trim($matches[0]);
    }
    
    echo "<entry>\n";
    echo '  <title type="text">'.htmlentities($relPath).'</title>'."\n";
    echo '  <id>'.htmlentities(substr($_SERVER['REQUEST_URI'],0,strrpos($_SERVER['REQUEST_URI'],'/')+1).$relPath).'</id>'."\n";
    echo '  <updated>'.date('Y-m-d\TH:i:sP',filemtime($fullPath)).'</updated>'."\n";
    if(ensure_uri($url))
        echo '  <link href="'.ensure_uri($url).'" />'."\n";
    if(ensure_uri($attachmentPathOrUrl))
        echo '  <link href="'.ensure_uri($attachmentPathOrUrl).'" rel="enclosure" length="'.get_filesize($attachmentPathOrUrl).'" />'."\n";
    
    if($parseContent){
      if($isHtml){
          echo '  <content type="html">'."\n"; // If type is "html" then content must be escaped. If "xhtml" then must contain outer div element but not escaped. See https://tools.ietf.org/html/rfc4287#section-4.1.3.3
          //echo '    '.htmlentities('<div>'.$content.'</div>')."\n";
          echo '    '.htmlspecialchars('<div>'.$content.'</div>')."\n";
      }
      else{
          echo '  <content type="text">'; // If type is "html" then content must be escaped. If "xhtml" then must contain outer div element but not escaped. See https://tools.ietf.org/html/rfc4287#section-4.1.3.3
          echo htmlentities($content);//str_replace('\n','\\n',$content);
      }
      echo '</content>'."\n";
    }
    echo "</entry>\n";
}
function get_scheme(){
    return (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS'])?'https':'http';
}
function main(){
    global $DIR2RSS_SEARCH_PATTERN,$DIR2RSS_PATH;
    $p=$DIR2RSS_PATH;
    if(!$p)$p='.';
    $path=realpath($p);
    $files=scandir_recursive($path,false,true);
    sort($files,SORT_STRING|SORT_FLAG_CASE); // Sort files by name
    $title=substr($path,strrpos(str_replace('\\','/',$path),'/')+1);
    $icon=null;
    
    header('Content-Type: application/atom+xml');
    echo '<?xml version="1.0" encoding="utf-8"?>'."\n";
    echo '<feed xmlns="http://www.w3.org/2005/Atom">'."\n";
    echo '<title>'.$title.'</title>'."\n";
    echo '<id>'.get_scheme().'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'].'</id>'."\n";
    echo '<updated>'.date('Y-m-d\TH:i:sP',filemtime($path)).'</updated>'."\n";
    echo '<link href="'.get_scheme().'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'].'"></link>'."\n";
    if($icon){
        echo '<logo>'.$icon.'</logo>'."\n";
        echo '<icon>'.$icon.'</icon>'."\n";
    }
    
    foreach($files as $relPath)
    {
        if($relPath=='..'||$relPath=='.')
            continue;
        
        $fullPath=$path.'/'.$relPath;
        
        if(preg_match($DIR2RSS_SEARCH_PATTERN,$relPath)!==1){
            continue;
        }
            
        $isHtml=FALSE;
        $parseContent=FALSE;
        $attachmentUrl=NULL;
        
        if(is_file($fullPath)){
            if(preg_match('/\.(txt|html?|log)?$/i',$relPath))
                $parseContent=true;
            if(preg_match('/\.html?$/i',$relPath))
                $isHtml=true;
            if(preg_match('/\.(ogg|aac|mp3|mp4|mkv|m4v|webm|wmv|mov|avi|flv|f4v)$/i',$relPath))
                $attachmentUrl=$fullPath;
        }
        renderFile($relPath,$fullPath,$isHtml=$isHtml,$parseContent=$parseContent,$url=$fullPath,$attachmentUrl=$attachmentUrl);
    }
    echo '</feed>'."\n";
}
main();
?>
