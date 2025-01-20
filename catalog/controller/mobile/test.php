<?php

namespace Opencart\Catalog\Controller\Mobile;

class Test extends ApiController
{
    public function connection(){
        $this->response->setOutput($this->jsonp(['Connection success!'], true));

        return;
    }
}