# yii2-prestashop
 Modulo per integrare la  Yii con Prestashop
 
 [Prestashop Technical API Documentation](https://devdocs.prestashop-project.org/8/webservice/tutorials/prestashop-webservice-lib/)
 
 Installation
 ------------
 
 The preferred way to install this extension is through [composer](http://getcomposer.org/download/).
 
 Run
 
 ```
 composer require "magicalella/yii2-prestashop" "*"
 ```
 
 or add
 
 ```
 "magicalella/yii2-prestashop": "*"
 ```
 
 to the require section of your `composer.json` file.
 
 Usage
 -----
 
 1. Add component to your config file
 ```php
 'components' => [
     // ...
     'prestashop' => [
         'class' => 'magicalella\prestashop\Prestashop',
         'url' => 'URL Site prestashop',
         'debug' => true/false,
         'version' => 'version PS'
     ],
 ]
 ```
 
 2. Add new contact to Prestashop . Patch a contact by email. If contact does not exists, it is created
 ```php
 $prestashop = Yii::$app->prestashop;
 $prestashop->$url = $this->$url;
 $prestashop->key = $this->key;
 $prestashop->debug = $this->debug;
 try
  {
     $xml = $prestashop->get(['resource' => 'orders','limit'=> $off_set.','.$limit,'sort'=>'id_ASC']);
  }
 catch (BridgePSException $ex)
  {
     echo 'Error : '.$ex->getMessage();
 }
 ```
 
 Check [Prestashop Technical API Documentation](https://devdocs.prestashop-project.org/8/webservice/tutorials/advanced-use/) for all available options.
