<div class="section">
<div class="location">当前位置 > 模型(Model)</div>
<div class="section-title">添加模型</div>
<div class="section-body">
<form id="" action="<?php echo ocUrl(array(OC_DEV_SIGN, 'home', 'adminModel'));?>" method="post">
	
<div>
<span class="left-span">数据库服务器名称：</span>
<input type="text" name="server" id="server" value="default">
</div>

<div>
    <span class="left-span">数据库名：</span>
    <input type="text" name="database" id="database" value="default">
</div>

<div>
<span class="left-span">数据表名</span>
<input type="text" value="" name="table" id="table">
</div>

<div>
    <span class="left-span">模型名称（不填默认为表名）</span>
    <input type="text" value="" name="model" id="model">
</div>

<div>
<span class="left-span">主键字段</span>
<input type="text" value="" name="primarys" id="primarys">
</div>
	
<div>
<span class="left-span">&nbsp;</span>
<input type="submit" value="提交" name="submit" />
</div>
</form>
</div>
</div>