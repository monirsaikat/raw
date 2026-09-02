<?php

class View
{
    public function __construct(
        private $smarty
    ) {}

    public function render(string $template, array $data = [])
    {
        $this->smarty->assign($data);

        return $this->smarty->fetch($template . '.tpl');
    }

    public function display(string $template, array $data = [])
    {
        echo $this->render($template, $data);
    }
}