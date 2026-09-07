<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:38
  from 'file:views/home.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b98631b750_67222839',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'bf9b1fed44bcbac3a53011a87289082e073c8cee' => 
    array (
      0 => 'views/home.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b98631b750_67222839 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, true);
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_11692457326a99b9860e8c91_09407562', 'title');
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_6823349726a99b98611fbe1_46746373', 'content');
?>

<?php $_smarty_tpl->getInheritance()->endChild($_smarty_tpl, 'layouts/main.tpl', $_smarty_current_dir);
}
/* {block 'title'} */
class Block_11692457326a99b9860e8c91_09407562 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>
YourApp — Placeholder Homepage<?php
}
}
/* {/block 'title'} */
/* {block 'content'} */
class Block_6823349726a99b98611fbe1_46746373 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>


<section class="hero">
    <div class="container">
        <h1>Build something great, faster</h1>
        <p class="lead mx-auto">
            This is placeholder copy for your homepage hero. Swap it out with your
            own headline and description once the design is ready.
        </p>
        <div class="d-flex justify-content-center gap-2 mt-4">
            <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'contact'), $_smarty_tpl);?>
" class="btn btn-brand px-4 py-2">Get Started</a>
            <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'about'), $_smarty_tpl);?>
" class="btn btn-brand-outline px-4 py-2">Learn More</a>
        </div>
    </div>
</section>

<section class="container mb-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h2 class="h4 mb-0">Latest items</h2>
        <span class="text-muted small">placeholder list</span>
    </div>

    <div class="rank-list">
        <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('items'), 'item');
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('item')->value) {
$foreach0DoElse = false;
?>
            <div class="rank-row">
                <div class="rank-number">#<?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('item')['rank']), ENT_QUOTES, 'UTF-8');?>
</div>
                <div class="rank-icon"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('item')['initial']), ENT_QUOTES, 'UTF-8');?>
</div>
                <div class="rank-body">
                    <h3><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('item')['title']), ENT_QUOTES, 'UTF-8');?>
</h3>
                    <p><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('item')['description']), ENT_QUOTES, 'UTF-8');?>
</p>
                </div>
                <div class="rank-meta"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('item')['meta']), ENT_QUOTES, 'UTF-8');?>
</div>
            </div>
        <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">1</div>
                <h3 class="h6">Feature one</h3>
                <p class="text-muted small mb-0">Short placeholder description of the first feature goes here.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">2</div>
                <h3 class="h6">Feature two</h3>
                <p class="text-muted small mb-0">Short placeholder description of the second feature goes here.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">3</div>
                <h3 class="h6">Feature three</h3>
                <p class="text-muted small mb-0">Short placeholder description of the third feature goes here.</p>
            </div>
        </div>
    </div>
</section>

<?php
}
}
/* {/block 'content'} */
}
