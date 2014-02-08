<?php
/*
 *  Copyright 2013 Christian Grobmeier
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *  http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing,
 *  software distributed under the License is distributed
 *  on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
 *  either express or implied. See the License for the specific
 *  language governing permissions and limitations under the License.
 */
namespace Cicada\Routing;

use Cicada\Application;
use Cicada\Validators\Validator;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Route
{
    /**
     * Regex patter which matches this route.
     */
    private $pattern;

    /**
     * Callback to be executed on matched route.
     * @var callable
     */
    private $callback;

    /**
     * HTTP method to match.
     */
    private $method = 'GET';

    private $allowedPostFields = array();

    private $allowedGetFields = array();

    private $before = [];

    private $after = [];

    function __construct($pattern, $callback, $method = 'GET')
    {
        $this->pattern = '/' . str_replace('/', '\\/', $pattern) . '/';
        $this->callback = $callback;
        $this->method = $method;
    }

    /**
     * Checks whether this route matches the given url.
     */
    public function matches($url)
    {
        if (preg_match($this->pattern, $url, $matches)) {
            $this->cleanMatches($matches);
            return $matches;
        }

        return false;
    }

    public function run(Application $app, Request $request, array $matches)
    {
        // Validate the request
        $this->validate($request);

        // Call before
        foreach($this->before as $before) {
            $result = $before($app, $request);
            if (isset($result)) {
                return $result;
            }
        }

        // Process callback
        $callback = $this->callback;
        if (is_string($callback) && strpos('::', $callback) !== false) {
            $callback = $this->parseClassCallback($callback);
        }

        // Add application and request as first two params
        $arguments = array_merge([$app, $request], $matches);

        if (is_callable($callback)) {
            $response = call_user_func_array($callback, $arguments);
        } else {
            return new Response("Invalid callback", Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Call after
        foreach($this->after as $after) {
            $after($app, $request, $response);
        }

        return $response;
    }

    public function allowGetField($fieldName, $validators = null)
    {
        $this->allowedGetFields[] = $this->wrapField($fieldName, $validators);
        return $this;
    }

    public function allowPostField($fieldName, $validators = null)
    {
        $this->allowedPostFields[] = $this->wrapField($fieldName, $validators);
        return $this;
    }

    public function before($callback)
    {
        $this->before[] = $callback;
        return $this;
    }

    public function after($callback)
    {
        $this->after[] = $callback;
        return $this;
    }

    public function getPattern()
    {
        return $this->pattern;
    }

    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Parses a string like "SomeClass::someMethod" and returns a corresponding
     * callable array for method someMehod on a new instance of SomeClass.
     */
    private function parseClassCallback($callback)
    {
        list($class, $method) = explode('::', $callback);

        if (!class_exists($class)) {
            throw new \Exception("Class $class does not exist.");
        }

        $object = new $class();

        if (!method_exists($object, $method)) {
            throw new \Exception("Method $class::$method does not exist.");
        }

        return [$object, $method];
    }

    private function validate($request)
    {
        $this->validateMethod($request);
        $this->validateGet($request);
        $this->validatePost($request);
    }

    private function validateMethod(Request $request)
    {
        $method = $request->getMethod();
        if ($method !== $this->method) {
            throw new \UnexpectedValueException("Method: $method not allowed for this request.");
        }
    }

    private function validateGet(Request $request)
    {
        $getFields = $request->query->all();
        $this->validateFields($getFields, $this->allowedGetFields);
    }

    private function validatePost(Request $request)
    {
        $postFields = $request->request->all();
        $this->validateFields($postFields, $this->allowedPostFields);
    }

    private function validateFields($in, $allowedFields)
    {
        $keys = array_keys($in);

        foreach ($keys as $key) {
            $found = false;
            foreach ($allowedFields as $allowed) {
                if ($allowed->fieldName == $key) {
                    $found = true;

                    if (isset($allowed->validators)) {
                        /** @var $validator Validator */
                        foreach ($allowed->validators as $validator) {
                            $validator->validate($in[$key], $key);
                        }
                    }

                    break;
                }
            }
            if (!$found) {
                throw new \UnexpectedValueException("Field $key not allowed.");
            }
        }
    }

    private function wrapField($fieldName, $validators = null)
    {
        $allowed = new \stdClass();
        $allowed->fieldName = $fieldName;
        $allowed->validators = $validators;
        return $allowed;
    }

    /**
     * Removes entries with integer keys from given array. Used to filter out
     * only named matches from $matches array produced by preg_match.
     */
    private function cleanMatches(array &$matches) {
        foreach ($matches as $key => $value) {
            if (is_int($key)) {
                unset($matches[$key]);
            }
        }
    }
}