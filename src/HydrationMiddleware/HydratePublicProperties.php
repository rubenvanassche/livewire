<?php

namespace Livewire\HydrationMiddleware;

use Livewire\Exceptions\PublicPropertyTypeNotAllowedException;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionProperty;
use Livewire\Wireable;
use Carbon\Carbon;
use DateTime;
use stdClass;

class HydratePublicProperties implements HydrationMiddleware
{
    use SerializesAndRestoresModelIdentifiers;

    public static function hydrate($instance, $request)
    {
        $publicProperties = $request->memo['data'] ?? [];

        $dates = data_get($request, 'memo.dataMeta.dates', []);
        $collections = data_get($request, 'memo.dataMeta.collections', []);
        $models = data_get($request, 'memo.dataMeta.models', []);
        $modelCollections = data_get($request, 'memo.dataMeta.modelCollections', []);
        $stringables = data_get($request, 'memo.dataMeta.stringables', []);
        $wireables = data_get($request, 'memo.dataMeta.wireables', []);

        foreach ($publicProperties as $property => $value) {
            if ($type = data_get($dates, $property)) {
                $types = [
                    'native' => DateTime::class,
                    'carbon' => Carbon::class,
                    'illuminate' => IlluminateCarbon::class,
                ];

                data_set($instance, $property, new $types[$type]($value));
            } else if (in_array($property, $collections)) {
                data_set($instance, $property, collect($value));
            } else if ($serialized = data_get($models, $property)) {
                static::hydrateModel($serialized, $property, $request, $instance);
            } else if ($serialized = data_get($modelCollections, $property)) {
                static::hydrateModels($serialized, $property, $request, $instance);
            } else if (in_array($property, $stringables)) {
                data_set($instance, $property, new Stringable($value));
            } else if (in_array($property, $wireables) && version_compare(PHP_VERSION, '7.4', '>=')) {
                $type = (new \ReflectionClass($instance))
                    ->getProperty($property)
                    ->getType()
                    ->getName();

                data_set($instance, $property, $type::fromLivewire($value));
            } else {
                // If the value is null and the property is typed, don't set it, because all values start off as null and this
                // will prevent Typed properties from wining about being set to null.
                if (version_compare(PHP_VERSION, '7.4', '<')) {
                    $instance->$property = $value;
                } else {
                    // Do not use reflection for virtual component properties.
                    if (property_exists($instance, $property) && (new ReflectionProperty($instance, $property))->getType()){
                        is_null($value) || $instance->$property = $value;
                    } else {
                        $instance->$property = $value;
                    }
                }

            }
        }
    }

    public static function dehydrate($instance, $response)
    {
        $publicData = $instance->getPublicPropertiesDefinedBySubClass();

        data_set($response, 'memo.data', []);
        data_set($response, 'memo.dataMeta', []);

        array_walk($publicData, function ($value, $key) use ($instance, $response) {
            if (
                // The value is a supported type, set it in the data, if not, throw an exception for the user.
                is_bool($value) || is_null($value) || is_array($value) || is_numeric($value) || is_string($value)
            ) {
                data_set($response, 'memo.data.'.$key, $value);
            } else if ($value instanceof QueueableEntity) {
                static::dehydrateModel($value, $key, $response, $instance);
            } else if ($value instanceof QueueableCollection) {
                static::dehydrateModels($value, $key, $response, $instance);
            } else if ($value instanceof Collection) {
                $response->memo['dataMeta']['collections'][] = $key;

                data_set($response, 'memo.data.'.$key, $value->toArray());
            } else if ($value instanceof DateTime) {
                if ($value instanceof IlluminateCarbon) {
                    $response->memo['dataMeta']['dates'][$key] = 'illuminate';
                } elseif ($value instanceof Carbon) {
                    $response->memo['dataMeta']['dates'][$key] = 'carbon';
                } else {
                    $response->memo['dataMeta']['dates'][$key] = 'native';
                }

                data_set($response, 'memo.data.'.$key, $value->format(\DateTimeInterface::ISO8601));
            } else if ($value instanceof Stringable) {
                $response->memo['dataMeta']['stringables'][] = $key;

                data_set($response, 'memo.data.'.$key, $value->__toString());
            } else if ($value instanceof Wireable && version_compare(PHP_VERSION, '7.4', '>=')) {
                $response->memo['dataMeta']['wireables'][] = $key;

                data_set($response, 'memo.data.'.$key, $value->toLivewire());
            } else {
                throw new PublicPropertyTypeNotAllowedException($instance::getName(), $key, $value);
            }
        });
    }

    protected static function hydrateModel($serialized, $property, $request, $instance)
    {
        if (isset($serialized['id'])) {
            $model = (new static)->getRestoredPropertyValue(
                new ModelIdentifier($serialized['class'], $serialized['id'], $serialized['relations'], $serialized['connection'])
            );
        } else {
            $model = new $serialized['class'];
        }

        $dirtyModelData = $request->memo['data'][$property];

        static::setDirtyData($model, $dirtyModelData);

        $instance->$property = $model;
    }

    protected static function hydrateModels($serialized, $property, $request, $instance)
    {
        $idsWithNullsIntersparsed = $serialized['id'];

        $models = (new static)->getRestoredPropertyValue(
            new ModelIdentifier($serialized['class'], $serialized['id'], $serialized['relations'], $serialized['connection'])
        );

        // Use `loadMissing` here incase loading collection relations gets fixed in Laravel framework,
        // in which case we don't want to load relations again.
        $models->loadMissing($serialized['relations']);

        $dirtyModelData = $request->memo['data'][$property];

        foreach ($idsWithNullsIntersparsed as $index => $id) {
            if (is_null($id)) {
                $model = new $serialized['class'];
                $models->splice($index, 0, [$model]);
            }

            static::setDirtyData(data_get($models, $index), data_get($dirtyModelData, $index, []));
        }

        $instance->$property = $models;
    }

    public static function setDirtyData($model, $data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $existingData = data_get($model, $key);

                if (is_array($existingData)) {
                    $updatedData = static::setDirtyData([], data_get($data, $key));
                } else {
                    $updatedData = static::setDirtyData($existingData, data_get($data, $key));
                }
            } else {
                $updatedData = data_get($data, $key);
            }
            data_set($model, $key, $updatedData);
        }

        return $model;
    }

    protected static function dehydrateModel($value, $property, $response, $instance)
    {
        $serializedModel = $value instanceof QueueableEntity && ! $value->exists
            ? ['class' => get_class($value)]
            : (array) (new static)->getSerializedPropertyValue($value);

        // Deserialize the models into the "meta" bag.
        data_set($response, 'memo.dataMeta.models.'.$property, $serializedModel);

        $filteredModelData = static::filterData($instance, $property);

        // Only include the allowed data (defined by rules) in the response payload
        data_set($response, 'memo.data.'.$property, $filteredModelData);
    }

    protected static function dehydrateModels($value, $property, $response, $instance)
    {
        $serializedModel = (array) (new static)->getSerializedPropertyValue($value);

        // Deserialize the models into the "meta" bag.
        data_set($response, 'memo.dataMeta.modelCollections.'.$property, $serializedModel);

        $filteredModelData = static::filterData($instance, $property);

        // Only include the allowed data (defined by rules) in the response payload
        data_set($response, 'memo.data.'.$property, $filteredModelData);
    }

    public static function filterData($instance, $property) {
        $data = $instance->$property->toArray();

        $rules = $instance->rulesForModel($property)->keys();

        $rules = static::processRules($rules)->get($property, []);

        return static::extractData($data, $rules, []);
    }

    public static function processRules($rules) {
        $rules = Collection::wrap($rules);

        // Map to groups
        $rules = $rules
            ->mapInto(Stringable::class)
            ->mapToGroups(function($rule) {
                return [$rule->before('.')->__toString() => $rule->after('.')];
            });

        // Go through groups and process rules
        $rules = $rules->mapWithKeys(function($rules, $group) {
            // Split rules into collection and model rules
            [$collectionRules, $modelRules] = $rules
                ->partition(function($rule) {
                    return $rule->contains('.');
                });

            // If collection rules exist, and value of * in model rules, remove * from model rule
            if ($collectionRules->count()) {
                $modelRules = $modelRules->reject(function($value) {
                    return ((string) $value) === '*';
                });
            }

            // Recurse through collection rules
            $collectionRules = static::processRules($collectionRules);

            // Convert model rule stringable object back to string
            $modelRules = $modelRules->map->__toString();

            $rules = $modelRules->merge($collectionRules);

            return [$group => $rules];
        });

        return $rules;
    }

    public static function extractData($data, $rules, $filteredData)
    {
        foreach($rules as $key => $rule) {
            if ($key === '*') {
                if ($data) {
                    foreach($data as $item) {
                        $filteredData[] = static::extractData($item, $rule, []);
                    }
                }
            } else {
                if (is_array($rule) || $rule instanceof Collection) {
                    $newFilteredData = data_get($data, $key) instanceof stdClass ? new stdClass : [];
                    data_set($filteredData, $key, static::extractData(data_get($data, $key), $rule, $newFilteredData));
                } else {
                    if ($rule == "*") {
                        $filteredData = $data;
                    } elseif ((Arr::accessible($data) && Arr::exists($data, $rule)) || (is_object($data) && isset($data->{$rule}))) {
                        data_set($filteredData, $rule, data_get($data, $rule));
                    }
                }
            }
        }

        return $filteredData;
    }
}
