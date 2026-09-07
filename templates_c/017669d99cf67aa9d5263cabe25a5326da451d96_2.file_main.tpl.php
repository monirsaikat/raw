<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:38
  from 'file:layouts/main.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b986487471_80994996',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '017669d99cf67aa9d5263cabe25a5326da451d96' => 
    array (
      0 => 'layouts/main.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b986487471_80994996 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, false);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('asset')->handle(array('path'=>'assets/vendor/bootstrap/css/bootstrap.min.css'), $_smarty_tpl);?>
" rel="stylesheet">
    <link href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('asset')->handle(array('path'=>'assets/css/app.css'), $_smarty_tpl);?>
" rel="stylesheet">

    <title><?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_6903910616a99b98636f802_18124168', 'title');
?>
</title>

    <?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_3775557566a99b98637fff0_52835667', 'styles');
?>

</head>

<body>

    <nav class="navbar navbar-expand-md site-navbar">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'home'), $_smarty_tpl);?>
">
                <span class="brand-mark">Y</span>
                YourApp
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav"
                aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'home'), $_smarty_tpl);?>
">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'about'), $_smarty_tpl);?>
">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'contact'), $_smarty_tpl);?>
">Contact</a>
                    </li>
                    <?php if ($_smarty_tpl->getValue('auth_user')) {?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'account'), $_smarty_tpl);?>
"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('auth_user')['name']), ENT_QUOTES, 'UTF-8');?>
</a>
                        </li>
                        <li class="nav-item ms-md-2">
                            <form method="post" action="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'logout'), $_smarty_tpl);?>
" class="d-inline">
                                <?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('csrf_field')->handle(array(), $_smarty_tpl);?>

                                <button type="submit" class="btn btn-brand-outline btn-sm px-3">Log out</button>
                            </form>
                        </li>
                    <?php } else { ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'login'), $_smarty_tpl);?>
">Login</a>
                        </li>
                        <li class="nav-item ms-md-2">
                            <a class="btn btn-brand btn-sm px-3" href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'register'), $_smarty_tpl);?>
">Sign up</a>
                        </li>
                    <?php }?>
                </ul>
            </div>
        </div>
    </nav>

    <?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_19981164626a99b98641a0a8_24657685', 'content');
?>


    <footer class="site-footer">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <span>&copy; <?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('current_year')->handle(array(), $_smarty_tpl);?>
 YourApp. All rights reserved.</span>
            <div class="d-flex gap-3">
                <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'home'), $_smarty_tpl);?>
">Home</a>
                <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'about'), $_smarty_tpl);?>
">About</a>
                <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'contact'), $_smarty_tpl);?>
">Contact</a>
            </div>
        </div>
    </footer>

    <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('asset')->handle(array('path'=>'assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), $_smarty_tpl);?>
"><?php echo '</script'; ?>
>
</body>

</html>
<?php }
/* {block 'title'} */
class Block_6903910616a99b98636f802_18124168 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
?>
YourApp<?php
}
}
/* {/block 'title'} */
/* {block 'styles'} */
class Block_3775557566a99b98637fff0_52835667 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
}
}
/* {/block 'styles'} */
/* {block 'content'} */
class Block_19981164626a99b98641a0a8_24657685 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
}
}
/* {/block 'content'} */
}
