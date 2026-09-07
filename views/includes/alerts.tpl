{* Flash messages set with flash('success'|'error'|'warning'|'info', 'text') *}
{if isset($flash.success) || isset($flash.error) || isset($flash.warning) || isset($flash.info)}
    <div class="container mt-3">
        {if isset($flash.success)}
            <div class="alert alert-success" role="alert">{$flash.success}</div>
        {/if}
        {if isset($flash.error)}
            <div class="alert alert-danger" role="alert">{$flash.error}</div>
        {/if}
        {if isset($flash.warning)}
            <div class="alert alert-warning" role="alert">{$flash.warning}</div>
        {/if}
        {if isset($flash.info)}
            <div class="alert alert-info" role="alert">{$flash.info}</div>
        {/if}
    </div>
{/if}
