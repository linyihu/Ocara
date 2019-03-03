<div class="section">
<div class="location">当前位置 > 动作(Action)</div>
<div class="section-title">添加动作</div>
<div class="section-body">
<form id="" action="<?php echo ocUrl(array(OC_MODULE_NAME, 'generate', 'action'), array('target' => 'action'));?>" method="post">
<div>
    <span class="left-span">模块类型</span>
    <input type="radio" value="" name="mdltype" id="mdltype1" checked /> 默认全局&nbsp;
    <input type="radio" value="modules" name="mdltype" id="mdltype2" /> 普通模块（modules）
    <input type="radio" value="console" name="mdltype" id="mdltype3" /> 命令模块（console）
    <input type="radio" value="assist" name="mdltype" id="mdltype4" /> 工具模块（assist）
</div>

<div>
<span class="left-span">控制器名/动作名：</span>
<input type="text" name="actname" id="actname" />
<span class="right-span">有模块时：模块名/控制器名/动作名。</span>
</div>

<div>
<span class="left-span">是否新建模板</span>
<input type="checkbox" name="createview" id="createview" checked />
</div>

<div>
<span class="left-span">模板类型</span>
<input type="text" name="ttype" id="ttype" value="defaults" checked />
</div>

<div><span class="left-span">&nbsp;</span><input type="submit" value="提交" name="submit" /></div>
	
</form>
</div>
</div>