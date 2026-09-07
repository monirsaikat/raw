<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:45
  from 'file:views/auth/register.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b98d60d696_04842744',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '027da84f9469b7c76002557889776dd486c5f5ac' => 
    array (
      0 => 'views/auth/register.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b98d60d696_04842744 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, true);
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_6799422176a99b98d5e1f71_65115431', 'title');
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_6926627756a99b98d5e4229_86836033', 'content');
?>

<?php $_smarty_tpl->getInheritance()->endChild($_smarty_tpl, 'layouts/main.tpl', $_smarty_current_dir);
}
/* {block 'title'} */
class Block_6799422176a99b98d5e1f71_65115431 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
?>
Register — YourApp<?php
}
}
/* {/block 'title'} */
/* {block 'content'} */
class Block_6926627756a99b98d5e4229_86836033 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views\\auth';
?>


<section class="hero pb-4">
    <div class="container">
        <h1>Create an account</h1>
        <p class="lead mx-auto">Placeholder copy inviting people to sign up.</p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="contact-card">

                <form method="post" action="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'register.store'), $_smarty_tpl);?>
" novalidate>
                    <?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('csrf_field')->handle(array(), $_smarty_tpl);?>


                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control<?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['name'] ?? null)))) {?> is-invalid<?php }?>"
                            id="name" name="name" value="<?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('old')['name']), ENT_QUOTES, 'UTF-8');?>
">
                        <?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['name'] ?? null)))) {?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('errors')['name'][0]), ENT_QUOTES, 'UTF-8');?>
</div>
                        <?php }?>
                    </div>

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
                        <input type="password" class="form-control<?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['password'] ?? null)))) {?> is-invalid<?php }?>"
                            id="password" name="password">
                        <?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['password'] ?? null)))) {?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('errors')['password'][0]), ENT_QUOTES, 'UTF-8');?>
</div>
                        <?php }?>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation">
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2 w-100">Create account</button>
                </form>

                <p class="text-center text-muted small mt-3 mb-0">
                    Already have an account? <a href="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'login'), $_smarty_tpl);?>
">Log in</a>
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
