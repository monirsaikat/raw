{include file='includes/header.tpl'}

    <h1>Hello {$name}</h1>
    
    <h3>Products</h3>
    {foreach $products as $product}
        {include file='product.tpl' product_item=$product}
    {/foreach}
    
{include file='includes/footer.tpl'}
