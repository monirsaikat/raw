<?php
/* Smarty version 5.8.4, created on 2026-09-02 18:34:25
  from 'file:layouts/main.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a986c313829b7_45208179',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '017669d99cf67aa9d5263cabe25a5326da451d96' => 
    array (
      0 => 'layouts/main.tpl',
      1 => 1788373984,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a986c313829b7_45208179 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, false);
?>
<!doctype html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">

    <title>Hello, world!</title>

    <?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_17914443556a986c313118a2_30618068', 'styles');
?>

</head>

<body>
    <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'home'), $_smarty_tpl);?>
">Home</a>
    <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'about'), $_smarty_tpl);?>
">About</a>
    <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'x'), $_smarty_tpl);?>
">X</a>

    <?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_12861298936a986c313708f0_68989294', 'content');
?>


    <?php echo '<script'; ?>
 src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js">
    <?php echo '</script'; ?>
>
</body>

</html><?php }
/* {block 'styles'} */
class Block_17914443556a986c313118a2_30618068 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
}
}
/* {/block 'styles'} */
/* {block 'content'} */
class Block_12861298936a986c313708f0_68989294 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\layouts';
?>

    <?php
}
}
/* {/block 'content'} */
}
