<?php
/* Smarty version 5.8.4, created on 2026-09-03 18:16:43
  from 'file:views/contact.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.8.4',
  'unifunc' => 'content_6a99b98b81ec53_01553398',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '4ab24e43ee80b0b29806760b84c95eb8bb758df9' => 
    array (
      0 => 'views/contact.tpl',
      1 => 1788459386,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6a99b98b81ec53_01553398 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
$_smarty_tpl->getInheritance()->init($_smarty_tpl, true);
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_20139641016a99b98b7d75e4_68822431', 'title');
?>


<?php 
$_smarty_tpl->getInheritance()->instanceBlock($_smarty_tpl, 'Block_15524543966a99b98b7db9c1_29352531', 'content');
?>

<?php $_smarty_tpl->getInheritance()->endChild($_smarty_tpl, 'layouts/main.tpl', $_smarty_current_dir);
}
/* {block 'title'} */
class Block_20139641016a99b98b7d75e4_68822431 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>
Contact — YourApp<?php
}
}
/* {/block 'title'} */
/* {block 'content'} */
class Block_15524543966a99b98b7db9c1_29352531 extends \Smarty\Runtime\Block
{
public function callBlock(\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'D:\\xammp\\htdocs\\raw\\views';
?>


<section class="hero pb-4">
    <div class="container">
        <h1>Get in touch</h1>
        <p class="lead mx-auto">
            Placeholder copy inviting people to reach out. Fill in the form below
            and we'll get back to you.
        </p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="contact-card">

                <?php if ($_smarty_tpl->getValue('success')) {?>
                    <div class="alert alert-success" role="alert"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('success')), ENT_QUOTES, 'UTF-8');?>
</div>
                <?php }?>

                <form method="post" action="<?php echo $_smarty_tpl->getSmarty()->getFunctionHandler('navigate')->handle(array('name'=>'contact.store'), $_smarty_tpl);?>
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
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control<?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['message'] ?? null)))) {?> is-invalid<?php }?>"
                            id="message" name="message" rows="5"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('old')['message']), ENT_QUOTES, 'UTF-8');?>
</textarea>
                        <?php if ((true && (true && null !== ($_smarty_tpl->getValue('errors')['message'] ?? null)))) {?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars((string) ($_smarty_tpl->getValue('errors')['message'][0]), ENT_QUOTES, 'UTF-8');?>
</div>
                        <?php }?>
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2">Send message</button>
                </form>

            </div>
        </div>
    </div>
</section>

<?php
}
}
/* {/block 'content'} */
}
