<div class="section">
<div class="location">当前位置 > 用户(Users)</div>
<div class="section-title">用户管理</div>
<div class="section-body">
<form action="<?php echo ocUrl(array(OC_MODULE_NAME, 'generate', 'action'), array('target' => 'users'));?>" method="post">
	
<div>
<span class="left-span">用户名：</span>
<input type="text" value="" name="username" id="username">
</div>

<div>
<span class="left-span">密码：</span>
<input type="password" value="" name="password" id="password">
</div>


<div>
<span class="left-span">&nbsp;</span>
<input type="submit" value="提交" name="submit" />
</div>
</form>
</div>
</div>