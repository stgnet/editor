<?php
    function curl_get_contents($url)
    {
        $ch=curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        $contents=curl_exec($ch);
        curl_close($ch);
        return($contents);
    }
    if (get_magic_quotes_gpc())
    {
        function stripslashes_gpc(&$value)
        {
            $value = stripslashes($value);
        }
        array_walk_recursive($_GET, 'stripslashes_gpc');
        array_walk_recursive($_POST, 'stripslashes_gpc');
        array_walk_recursive($_COOKIE, 'stripslashes_gpc');
        array_walk_recursive($_REQUEST, 'stripslashes_gpc');
    }

    $version="v3.0alpha";
    global $password_locations;
    $password_locations=array('.','/tmp');

    if (array_key_exists('phpinfo',$_GET)) exit(phpinfo());
    if (array_key_exists('globals',$_GET)) exit('<html><body><pre>'.print_r($GLOBALS,true));

    if (array_key_exists('authfix',$_GET))
    {
        file_put_contents('.htaccess','<IfModule mod_rewrite.c>
  RewriteEngine on
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},L]
</IfModule>
');
        exit('Wrote .htaccess');
    }

    if (array_key_exists('update',$_GET))
    {
        $update=curl_get_contents("https://raw.github.com/stgnet/editor/master/editor.php");
        if (empty($update))
            exit("ERROR: Unable to download update file");
        file_put_contents("previous-editor.php",file_get_contents("editor.php"));
        file_put_contents("editor.php",$update);
        header("refresh:3;url=editor.php");
        exit("Update written to update.php - reloading...");
    }

    // username/password authentication
    $password_file='.editor'.str_replace('/','_',dirname($_SERVER['SCRIPT_FILENAME'])).'.php';
    foreach ($password_locations as $location)
    {
        $password_path=$location.'/'.$password_file;
        if (file_exists($password_path))
        {
            require($password_path);
            break;
        }
    }
	if (empty($editor_user) || empty($editor_pass))
	{
        // user/pass hasn't been set
		if (empty($_POST['new_editor_user']) || empty($_POST['new_editor_pass']))
		{
            // prompt for it
			exit("<html><h3>Enter authentication for editor:</h3>
			<form method=\"post\" action=\"editor.php\">
			Username: <input type=\"text\" name=\"new_editor_user\" /><br />
			Password: <input type=\"password\" name=\"new_editor_pass\" /><br />
			<input type=\"submit\" name=\"submit\" value=\"Submit\" />
			</form></html>");
		}
		else
		{
            // store it privately
			$editor_user=$_POST['new_editor_user'];
			$editor_pass=md5($editor_user.'&'.$_POST['new_editor_pass']);
            foreach ($password_locations as $location)
            {
                $password_path=$location.'/'.$password_file;
    			file_put_contents($password_path,'<'."?php
global \$editor_user,\$editor_pass;
\$editor_user='$editor_user';
\$editor_pass='$editor_pass';
");
                if (file_exists($password_path))
                {
			        chmod($password_path,0700);
                    header("refresh:3;url=editor.php");
                    exit("Saved password to $password_path");
                }
            }
            exit("ERROR: Unable to save password to $password_file");
		}
	}

    // if authoriztion not provided, force it
    if (empty($_SERVER['PHP_AUTH_USER']) || 
		empty($_SERVER['PHP_AUTH_PW']) ||
		$_SERVER['PHP_AUTH_USER']!=$editor_user ||
		md5($_SERVER['PHP_AUTH_USER'].'&'.$_SERVER['PHP_AUTH_PW'])!=$editor_pass)
	{
		header('HTTP/1.1 401 Unauthorized');
		header("WWW-Authenticate: Basic realm=\"Editor\"");
        //echo '<pre>'.print_r($GLOBALS,true);
		//echo $_SERVER['PHP_AUTH_USER'].'&'.$_SERVER['PHP_AUTH_PW'].'!='.$editor_pass."\n";
		//echo md5($_SERVER['PHP_AUTH_USER'].'&'.$_SERVER['PHP_AUTH_PW']).'!='.$editor_pass."\n";
        exit('Unauthorized');
	}

	if ($_SERVER['REQUEST_METHOD']=="POST" && !empty($_FILES['userfile']))
	{
		$userfile=$_FILES['userfile'];
		if (move_uploaded_file($userfile['tmp_name'],$userfile['name']))
		{
			$result="Upload completed to ".$userfile['name'];
		}
		else
		{
			$result="Upload failed: ".$userfile['error'];
		}

		echo '<html><H3>'.$result.'</H3></html>';
		exit(0);
	}

    // save file being edited
	if ($_SERVER['REQUEST_METHOD']=="POST")
    {
        if (empty($_POST['editfile'])) die('no filename');
        if (file_put_contents($_POST['editfile'],$_POST['content'])===false)
            die('Error writing file');
        chmod($_POST['editfile'],0755); // assume apache may need readability
        exit("-SUCCESS-");
    }

    if (array_key_exists('upload',$_GET))
	{
		$uploadForm=<<<EOF
<form enctype="multipart/form-data" action="editor.php" method="POST">
    <H3>Upload a file</H3>
    Upload file: <input name="userfile" type="file" />
    <input type="submit" value="Send" />
</form>
EOF;

		echo '<html>'.$uploadForm.'</html>';
		exit(0);
	}
    if (!empty($_GET['sh']))
    {
        header('Content-type: text/plain');
        exit(passthru($_GET['sh']));
    }
    // run command (shell prompt)
    if (!empty($_GET['cmd']))
    {
        $home=dirname($_SERVER['SCRIPT_FILENAME']);

        foreach ($_SERVER as $var => $value)
            putenv("$var=$value");

        $shboxd='shboxd-'.trim(`uname -m`);
        if (!file_exists($shboxd))
        {
            $url='https://raw.github.com/stgnet/editor/master/'.$shboxd;
            $executable=curl_get_contents($url);
            if (empty($executable) || $executable[0]=='<')
                exit("ERROR: Unable to download $shboxd");
            file_put_contents($shboxd,$executable);
			chmod($shboxd,0755);
        }

        $cmd=$_GET['cmd'];
        $fp=popen("./$shboxd --cgi -t -s \"/:\$(/usr/bin/id -u):\$(/usr/bin/id -g):$home:$cmd\" 2>&1","r");
        $valid=array('X-ShellInABox-Port','X-ShellInABox-Pid','Content-type');
        while ($line=trim(fgets($fp)))
        {
            if ($line=="") break;
            $exp=explode(':',$line,2);
            if (!in_array($exp[0],$valid))
                die("ERROR: ".$line);
            header($line);
        }
        while ($line=fgets($fp))
        {
            echo $line;
            if (trim($line)=="</html>") break;
        }
        exit(false);
    }

    // get a list of files
    class SortedIterator extends SplHeap
    {
        public function __construct(Iterator $iter)
        {
            foreach ($iter as $item)
                $this->insert($item);
        }
        public function compare($b, $a)
        {
            return strcmp($a->getRealpath(), $b->getRealpath());
        }
    }
    $files=new SortedIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".",RecursiveDirectoryIterator::FOLLOW_SYMLINKS)));

    $newfile="&#171; New File &#187;";

    $editfile='';
    if (!empty($_GET['file']))
        $editfile=$_GET['file'];

    // build form in toolbar
    $form="
<form class=\"form-inline\">
    <div class=\"input-append\">
        <button type=\"submit\" id=\"save\" class=\"btn\">Save</button>
        <span class=\"divider-vertical\"></span>
        <button type=\"submit\" id=\"run\" class=\"btn\">Run</button>
        <span class=\"divider-vertical\"></span>
        <select class=\"input\" id=\"file\" name=\"file\">
		<option>$newfile</option>
";
    foreach ($files as $file)
    {
        $path=substr($file,2);
        if ($path[0]=="." && $path!=".htaccess") continue;
        if (strstr($path,'/.')) continue;
        if (is_dir($path)) continue;
        if (!is_file($path)) continue;

        $sel='';
        if ($path==$editfile) $sel=" selected";

        $form.="<option value=\"$path\"$sel>$path</option>\n";
    }
    $form.="</select>
        <button type=\"submit\" class=\"btn\">Edit</button>
        <span class=\"divider-vertical\"></span>
		<a target=\"_blank\" href=\"editor.php?upload\" class=\"btn btn-default active\" role=\"button\">Upload</a>
        <a                   href=\"editor.php?update\" class=\"btn btn-default active\" role=\"button\">Update</a>
        <a target=\"_blank\" href=\"editor.php?cmd=sh\" class=\"btn btn-default active\" role=\"button\">Shell</a>
    </div>
</form>\n";

// HTML5 using Bootstrap & Codemirror 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="PHP Based Online Editor Tool">
	<meta name="author" content="http://github.com/stgnet/editor">
    <title>Editor</title>
    <link href="//maxcdn.bootstrapcdn.com/bootswatch/3.2.0/cerulean/bootstrap.min.css" rel="stylesheet">
    <link href="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/codemirror.css" rel="stylesheet">
    <style type="text/css">
        .CodeMirror {
            border: 1px solid #eee;
            height: auto;
        }
        .CodeMirror-scroll {
            overflow-y: hidden;
            overflow-x: auto;
        }
    </style>
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
</head>
<body>
    <div class="navbar navbar-default navbar-fixed-top" role="navigation">
      <div class="container-fluid">
        <div class="navbar-header">
          <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target=".navbar-collapse">
            <span class="sr-only">Toggle navigation</span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
          </button>
          <a class="navbar-brand" href="#">Editor <?php echo $version; ?></a>
        </div>
        <div class="navbar-collapse collapse">
          <ul class="nav navbar-nav">
			  <!---
            <li class="active"><a href="#">Home</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#contact">Contact</a></li>
            <li class="dropdown">
              <a href="#" class="dropdown-toggle" data-toggle="dropdown">Dropdown <span class="caret"></span></a>
              <ul class="dropdown-menu" role="menu">
                <li><a href="#">Action</a></li>
                <li><a href="#">Another action</a></li>
                <li><a href="#">Something else here</a></li>
                <li class="divider"></li>
                <li class="dropdown-header">Nav header</li>
                <li><a href="#">Separated link</a></li>
                <li><a href="#">One more separated link</a></li>
              </ul>
            </li>
				--->
          </ul>
          <ul class="nav navbar-nav navbar-right">
                <?php echo $form; ?>
          </ul>
        </div><!--/.nav-collapse -->
      </div>
    </div>

	<h1>&nbsp;</h1>
	<div id="workspace" style="height: auto; border: 1px solid;">
<?php
    // push the current file contents to codemirror via textarea
    $editfile='';
	$content="";
    if (!empty($_GET['file']))
    {
        $editfile=$_GET['file'];
		if (file_exists($editfile))
			$content=file_get_contents($editfile);
		else
			$editfile='';
	}
	echo "<input type=\"hidden\" id=\"editfile\" name=\"editfile\" value=\"$editfile\" />";
	echo '<textarea id="editbox">'.htmlentities($content).'</textarea>';

    // tail portion of HTML with JS libraries
?>
    </div>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/codemirror.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/mode/xml/xml.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/mode/javascript/javascript.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/mode/css/css.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/mode/clike/clike.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/codemirror/4.10.0/mode/php/php.js"></script>

<script type="text/javascript">

    // fix the iframe height
    function setIframeHeight()
    {
        var iframe=document.getElementById('runbox');
        var iframeWin = iframe.contentWindow || iframe.contentDocument.parentWindow;
        iframe.height = iframeWin.document.documentElement.scrollHeight || iframeWin.document.body.scrollHeight;
    };

    // after document load enable CodeMirror
$(document).ready(function(){
    var editor = CodeMirror.fromTextArea(document.getElementById("editbox"), {
        lineNumbers: true,
        lineWrapping: true,
        matchBrackets: true,
        mode: "application/x-httpd-php",
        indentUnit: 4,
        indentWithTabs: true,
        enterMode: "keep",
        tabMode: "shift",
    });
    // how to save edited file from CodeMirror (sends to POST above)
	function save(run)
	{
        var editfile=$('#editfile').val();
		if (editfile=='') editfile=prompt("Enter filename");
		editor.save();
        var content=editor.getValue();
        $.ajax({
            type: "POST",
            url: "editor.php",
            data: {editfile:editfile,content:content},
            success: function(data){
                if (data!='-SUCCESS-')
                    alert('SAVE FAILED: '+data);
                else
				if (run)
				{
					//window.open(editfile,'_blank');
					//window.focus();
                    $('#workspace').empty().append('<iframe id="runbox" onLoad="setIframeHeight()" width="100%" allowtransparency=true frameborder=0 scrolling=no src="'+editfile+'"></iframe>');
				}
				else
					window.location.href='editor.php?file='+editfile;
            },
            error: function(xhr){
                alert('ERROR: '+xhr.status+' '+xhr.statusText+' '+xhr.responseText);
            }
        });
	}
    // save and run buttons
    $('#save').click(function(){
		save(false);
        return false;
    });
	$('#run').click(function(){
		save(true);
		return false;
	});
	
});
</script>
</body>
</html>
