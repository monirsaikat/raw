<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:44
  from 'file:views/auth/login.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b98c5f0021_11414172',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '9c6cd6d0055ed9a904312958d17e978d44fc438f' => 
    array (
      0 => 'views/auth/login.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b98c5f0021_11414172 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, true);
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_7203394726a99b98c5d8f80_83809424', 'title');
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_2167426486a99b98c5dbb35_07966916', 'content');
?>

<?php $_smarty_tpl->getInheritance()->endChild($_smarty_tpl, 'layouts/main.tpl', $_smarty_current_dir);
}
/* {block 'title'} */
class Block_7203394726a99b98c5d8f80_83809424 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
?>
Login — YourApp<?php
}
}
/* {/block 'title'} */
/* {block 'content'} */
class Block_2167426486a99b98c5dbb35_07966916 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
?>


<section class="hero pb-4">
    <div class="container">
        <h1>Welcome back</h1>
        <p class="lead mx-auto">Placeholder copy inviting people to log in.</p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="contact-card">

                <form method="post" action="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'login.store'), $_smarty_tpl);?>
" novalidate>
                    <?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('csrf_field')->handle(array(), $_smarty_tpl);?>


                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control<?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['email'] ?? null)))) {?> is-invalid<?php }?>"
                            id="email" name="email" value="<?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('old')['email']), ENT_QUOTES, 'UTF-8');?>
">
                        <?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['email'] ?? null)))) {?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('errors')['email'][0]), ENT_QUOTES, 'UTF-8');?>
</div>
                        <?php }?>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password">
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2 w-100">Log in</button>
                </form>

                <p class="text-center text-muted small mt-3 mb-0">
                    Don't have an account? <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'register'), $_smarty_tpl);?>
">Register</a>
                </p>

            </div>
        </div>
    </div>
</section>

<?php
}
}
/* {/block 'content'} */
}
