<?php
use Ocara\Develop;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>Ocara框架开发者中心</title>
<?php ocImport(OC_DEV_DIR . 'css/index.php');?>
<style type="text/css">
#logo{float:left;}
#member{float:right;font-size:12px;width:300px;top: 15px;position: relative;margin-right:15px;}
#member span, #member a{display:block;float:right;height:25px;line-height:25px;padding:0;margin:0;}
#member a{padding-left:5px;font-weight:bold;}
</style>
</head>
<body>
<div>
<div id="header">
<div id="logo">Ocara框架开发者中心</div>
<div id="member">
<?php if(Develop::checkLogin()) {?>
<a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home','logout'));?>">登出</a> 
<span>您好 <font><?php echo $_SESSION['OC_DEV_USERNAME'];?></font>，欢迎使用本系统！ </span>
<?php } ?>
</div>
</div>
<div id="sep"></div>
<div id="main">
<div id="left-nav">
<ul class="left-menu">
	<li class="currentMenu"><a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home', 'adminModel'));?>" target="iframe-main">模型(Model)</a></li>
	<li><a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home','adminAction'));?>" target="iframe-main">动作(Action)</a></li>
	<li><a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home','adminControl'));?>" target="iframe-main">控制器(Controller)</a></li>
	<li><a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home','adminModule'));?>" target="iframe-main">模块(Module)</a></li>
	<?php if(Develop::checkLogin() && $_SESSION['OC_DEV_USERNAME'] == 'root') {?>
	<li><a href="<?php echo ocUrl(array(OC_DEV_SIGN,'home','adminUsers'));?>" target="iframe-main">用户管理</a></li>
	<?php } ?>
	
</ul>
</div>
<div id="right">
<iframe src="<?php echo ocUrl(array(OC_DEV_SIGN,'home','adminModel'));?>" frameborder="no" scrolling="no" id="iframe-main" name="iframe-main"></iframe>
</div>
</div>
<div id="footer"></div>
</div>
</body>
</html>