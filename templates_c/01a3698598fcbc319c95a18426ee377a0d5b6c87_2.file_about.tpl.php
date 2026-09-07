<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:41
  from 'file:views/about.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b9896c9b11_56438780',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '01a3698598fcbc319c95a18426ee377a0d5b6c87' => 
    array (
      0 => 'views/about.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b9896c9b11_56438780 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, true);
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_20558466306a99b9896bb2d9_29973682', 'title');
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_3774419936a99b9896c6203_85450721', 'content');
?>

<?php $_smarty_tpl->getInheritance()->endChild($_smarty_tpl, 'layouts/main.tpl', $_smarty_current_dir);
}
/* {block 'title'} */
class Block_20558466306a99b9896bb2d9_29973682 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>
About — YourApp<?php
}
}
/* {/block 'title'} */
/* {block 'content'} */
class Block_3774419936a99b9896c6203_85450721 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>


<section class="hero pb-4">
    <div class="container">
        <h1>About YourApp</h1>
        <p class="lead mx-auto">
            Placeholder copy describing what your company or product does, who
            it's for, and why it matters. Replace this paragraph with your own story.
        </p>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">10K+</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">99.9%</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">150+</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">24/7</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-4 align-items-start">
        <div class="col-md-6">
            <h2 class="h4">Our mission</h2>
            <p class="text-muted">
                Placeholder paragraph describing your mission. Explain the problem
                you're solving and why your approach is different, in a sentence or two
                per paragraph so it stays easy to scan.
            </p>
            <p class="text-muted mb-0">
                A second placeholder paragraph goes here, continuing the story or
                adding supporting detail about your team, product, or values.
            </p>
        </div>
        <div class="col-md-6">
            <div class="row g-3">
                <div class="col-12">
                    <div class="feature-card">
                        <div class="icon">V</div>
                        <h3 class="h6">Placeholder value</h3>
                        <p class="text-muted small mb-0">One line describing a value or principle your team follows.</p>
                    </div>
                </div>
                <div class="col-12">
                    <div class="feature-card">
                        <div class="icon">V</div>
                        <h3 class="h6">Another placeholder value</h3>
                        <p class="text-muted small mb-0">One line describing another value or principle.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container mb-5 text-center">
    <h2 class="h4">Want to know more?</h2>
    <p class="text-muted">We'd love to hear from you.</p>
    <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'contact'), $_smarty_tpl);?>
" class="btn btn-brand px-4 py-2">Contact us</a>
</section>

<?php
}
}
/* {/block 'content'} */
}
