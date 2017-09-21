<?php

namespace LaraSpells\Generators;

use LaraSpells\Schema\Schema;

class ServiceProviderGenerator extends ClassGenerator
{

    protected $schema;

    public function __construct(Schema $schema)
    {
        parent::__construct($schema->getServiceProviderClass());
        $this->schema = $schema;
        $this->initClass();
        $this->addMethodsFromReflection();
    }

    protected function initClass()
    {
        $schema = $this->schema;
        $this->setParentClass('Illuminate\Support\ServiceProvider');
        $this->setDocblock(function($docblock) use ($schema) {
            $authorName = $schema->getAuthorName();
            $authorEmail = $schema->getAuthorEmail();
            $docblock->addText("Generated by LaraSpell");
            $docblock->addAnnotation("author", "{$authorName} <{$authorEmail}>");
            $docblock->addAnnotation("created", date('r'));
        });
    }

    protected function setMethodRegister(MethodGenerator $method)
    {
        $configKey = $this->schema->getConfigKey();
        $method->setDocblock(function($docblock) {
            $docblock->addText("Register the application services.");
        });
    }

    protected function setMethodBoot(MethodGenerator $method)
    {
        $method = $this->addMethod('boot');
        $method->setDocblock(function($docblock) {
            $docblock->addText("Bootstrap the application services.");
        });
    }

}
