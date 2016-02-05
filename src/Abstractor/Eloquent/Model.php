<?php
namespace Anavel\Crud\Abstractor\Eloquent;

use Anavel\Crud\Contracts\Abstractor\Model as ModelAbstractorContract;
use Anavel\Crud\Abstractor\ConfigurationReader;
use Anavel\Crud\Contracts\Abstractor\RelationFactory as RelationFactoryContract;
use Anavel\Crud\Contracts\Abstractor\FieldFactory as FieldFactoryContract;
use ANavallaSuiza\Laravel\Database\Contracts\Dbal\AbstractionLayer;
use FormManager\ElementInterface;
use Illuminate\Database\Eloquent\Model as LaravelModel;
use App;
use Anavel\Crud\Contracts\Form\Generator as FormGenerator;
use Anavel\Crud\Abstractor\Exceptions\AbstractorException;
use Illuminate\Http\Request;

class Model implements ModelAbstractorContract
{
    use ConfigurationReader;

    protected $dbal;
    protected $relationFactory;
    protected $fieldFactory;
    protected $generator;

    protected $model;
    protected $config;

    protected $slug;
    protected $name;
    protected $instance;

    public function __construct($config, AbstractionLayer $dbal, RelationFactoryContract $relationFactory, FieldFactoryContract $fieldFactory, FormGenerator $generator)
    {
        if (is_array($config)) {
            $this->model = $config['model'];
            $this->config = $config;
        } else {
            $this->model = $config;
            $this->config = [];
        }

        $this->dbal = $dbal;
        $this->relationFactory = $relationFactory;
        $this->fieldFactory = $fieldFactory;
        $this->generator = $generator;
    }

    public function setSlug($slug)
    {
        $this->slug = $slug;

        return $this;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function setInstance($instance)
    {
        $this->instance = $instance;

        return $this;
    }

    public function getSlug()
    {
        return $this->slug;
    }

    public function getName()
    {
        return transcrud($this->name);
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getInstance()
    {
        return $this->instance;
    }

    public function isSoftDeletes()
    {
        return $this->getConfigValue('soft_deletes') ? true : false;
    }

    protected function getColumns($action, $withForeignKeys = false)
    {
        $tableColumns = $this->dbal->getTableColumns();

        $filteredColumns = [];
        foreach ($tableColumns as $name => $column) {
            $filteredColumns[str_replace('`', '', $name)] = $column;
        }
        $tableColumns = $filteredColumns;


        $foreignKeysName = [];
        if ($withForeignKeys === false) {
            $foreignKeys = $this->dbal->getTableForeignKeys();

            foreach ($foreignKeys as $foreignKey) {
                foreach ($foreignKey->getColumns() as $columnName) {
                    $foreignKeysName[] = $columnName;
                }
            }
        }

        $customDisplayedColumns = $this->getConfigValue($action, 'display');
        $customHiddenColumns = $this->getConfigValue($action, 'hide') ? : [];

        $columns = array();
        if (! empty($customDisplayedColumns) && is_array($customDisplayedColumns)) {
            foreach ($customDisplayedColumns as $customColumn) {
                if (! array_key_exists($customColumn, $tableColumns)) {
                    throw new AbstractorException("Column ".$customColumn." does not exist on ".$this->getModel());
                }

                $columns[$customColumn] = $tableColumns[$customColumn];
            }
        } else {
            foreach ($tableColumns as $name => $column) {
                if (in_array($name, $customHiddenColumns)) {
                    continue;
                }

                if (in_array($name, $foreignKeysName)) {
                    continue;
                }

                $columns[$name] = $column;
            }
        }

        return $columns;
    }

    public function getRelations()
    {
        $configRelations =  $this->getConfigValue('relations');

        $relations = [];

        if (! empty($configRelations)) {
            foreach ($configRelations as $relationName => $configRelation) {
                if (is_int($relationName)) {
                    $relationName = $configRelation;
                }

                $config = [];
                if ($configRelation !== $relationName) {
                    if (! is_array($configRelation)) {
                        $config['type'] = $configRelation;
                    } else {
                        $config = $configRelation;
                    }
                }

                $relations[] = $this->relationFactory->setModel($this->instance)
                    ->setConfig($config)
                    ->get($relationName);
            }
        }

        return $relations;
    }

    public function getListFields()
    {
        $columns = $this->getColumns('list');

        $fieldsPresentation = $this->getConfigValue('fields_presentation') ? : [];

        $fields = array();
        foreach ($columns as $name => $column) {
            $presentation = null;
            if (array_key_exists($name, $fieldsPresentation)) {
                $presentation = $fieldsPresentation[$name];
            }

            $config = [
                'name' => $name,
                'presentation' => $presentation,
                'form_type' => null,
                'validation' => null,
                'functions' => null
            ];

            $fields[] = $this->fieldFactory
                ->setColumn($column)
                ->setConfig($config)
                ->get();
        }

        return $fields;
    }

    public function getDetailFields()
    {
        $columns = $this->getColumns('detail');

        $fieldsPresentation = $this->getConfigValue('fields_presentation') ? : [];

        $fields = array();
        foreach ($columns as $name => $column) {
            $presentation = null;
            if (array_key_exists($name, $fieldsPresentation)) {
                $presentation = $fieldsPresentation[$name];
            }

            $config = [
                'name' => $name,
                'presentation' => $presentation,
                'form_type' => null,
                'validation' => null,
                'functions' => null
            ];

            $fields[] = $this->fieldFactory
                ->setColumn($column)
                ->setConfig($config)
                ->get();
        }

        return $fields;
    }

    public function getEditFields($withForeignKeys = false)
    {
        $columns = $this->getColumns('edit', $withForeignKeys);

        $fieldsPresentation = $this->getConfigValue('fields_presentation') ? : [];
        $formTypes = $this->getConfigValue('edit', 'form_types') ? : [];
        $validationRules = $this->getConfigValue('edit', 'validation') ? : [];
        $functions = $this->getConfigValue('edit', 'functions') ? : [];
        $defaults = $this->getConfigValue('edit', 'defaults') ? : [];

        $fields = array();
        foreach ($columns as $name => $column) {
            if (! in_array($name, $this->getReadOnlyColumns())) {
                $presentation = null;
                if (array_key_exists($name, $fieldsPresentation)) {
                    $presentation = $fieldsPresentation[$name];
                }

                $config = [
                    'name' => $name,
                    'presentation' => $presentation,
                    'form_type' => null,
                    'validation' => null,
                    'functions' => null
                ];

                if (array_key_exists($name, $formTypes)) {
                    $config['form_type'] = $formTypes[$name];
                }

                if (array_key_exists($name, $validationRules)) {
                    $config['validation'] = $validationRules[$name];
                }

                if (array_key_exists($name, $functions)) {
                    $config['functions'] = $functions[$name];
                }

                if (array_key_exists($name, $defaults)) {
                    $config['defaults'] = $defaults[$name];
                }

                $field = $this->fieldFactory
                    ->setColumn($column)
                    ->setConfig($config)
                    ->get();

                if (! empty($this->instance) && ! empty($this->instance->getAttribute($name))) {
                    $field->setValue($this->instance->getAttribute($name));
                }

                $fields[] = $field;
            }
        }

        return $fields;
    }

    protected function getReadOnlyColumns()
    {
        $columns = [LaravelModel::CREATED_AT, LaravelModel::UPDATED_AT];

        $columns[] = $this->dbal->getModel()->getKeyName();

        return $columns;
    }

    /**
     * @param string $action
     * @return ElementInterface
     */
    public function getForm($action)
    {
        $this->generator->setModelFields($this->getEditFields());
        $this->generator->setRelatedModelFields($this->getRelations());

        return $this->generator->getForm($action);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function persist(Request $request)
    {
        /** @var \ANavallaSuiza\Laravel\Database\Contracts\Manager\ModelManager $modelManager */
        $modelManager = App::make('ANavallaSuiza\Laravel\Database\Contracts\Manager\ModelManager');
        if (! empty($this->instance)) {
            $item = $this->instance;
        } else {
            $item = $modelManager->getModelInstance($this->getModel());
        }

        foreach ($this->getEditFields(true) as $field) {
            if (! $field->saveIfEmpty() && empty($request->input($field->getName()))) {
                continue;
            }

            $requestValue = $request->input($field->getName());

            if (get_class($field->getFormField()) === \FormManager\Fields\File::class) {
                if ($request->hasFile($field->getName())) {
                    $fileName = uniqid().'.'.$request->file($field->getName())->getClientOriginalExtension();
                    $modelFolder = $this->slug.DIRECTORY_SEPARATOR;

                    $request->file($field->getName())->move(
                        base_path(config('anavel-crud.uploads_path').$modelFolder),
                        $fileName
                    );

                    $requestValue = $modelFolder.$fileName;
                }
            }

            if (! empty($requestValue)) {
                $item->setAttribute(
                    $field->getName(),
                    $field->applyFunctions($requestValue)
                );
            }
        }

        $item->save();

        $this->setInstance($item);

        if (! empty($relations = $this->getRelations())) {
            foreach ($relations as $relation) {
                $relation->persist($request);
            }
        }

        return $item;
    }

    /**
     * @return array
     */
    public function getValidationRules()
    {
        return $this->generator->getValidationRules();
    }
}
