<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Problems</title>
    <link rel="shortcut icon" href="/quant/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/quant/bootstrap.css">
    <link rel="stylesheet" href="/quant/style.css">
    <script src="/quant/lodash.min.js"></script>
    <script src="/quant/storage.js"></script>
</head>
<body>
<div class="container">
    <div class="row mt-4 mb-4">
        <div class="col treeBlock" id="cacheTreeView"></div>
        <div class="col-1">
            <div class="d-flex justify-content-center">
                <button type="button" class="btn btn-primary align-middle" id="copyDatabaseObjectInCacheButton"> <<<
                </button>
            </div>
        </div>
        <div class="col treeBlock" id="databaseTreeView"></div>
    </div>
    <div class="row">
        <div class="col">
            <button type="button" class="btn btn-primary" id="createCacheObjectButton">+</button>
            <button type="button" class="btn btn-primary" id="renameCacheObjectButton">a</button>
            <button type="button" class="btn btn-primary" id="removeCacheObjectButton">-</button>
            &nbsp;
            <button type="button" class="btn btn-success" id="applyChangesButton">Apply</button>
            <button type="button" class="btn btn-danger float-end" id="resetButton">Reset</button>
        </div>
    </div>
    <script>

        /**
         * Functions
         */

        function getRandomString(i = 10) {
            let str = '';
            while (str.length < i) str += Math.random().toString(36).substring(2);
            return str.substring(0, i);
        }

        function getRandomInteger() {
            let randomNumber = Math.random();
            let numberLength = randomNumber.toString().split('.').pop().length;
            let zero = '';
            for (let i = 0; i < numberLength; i++) zero += 0;
            let number = 1 + '' + zero;
            return randomNumber * parseInt(number);
        }

        function getView(obj) {
            let randomId = getRandomString();
            if (obj.is_deleted) {
                return '<span>' + obj.label + '</span>'
            }
            return '<input id="' + randomId + '" type="radio" name="item" value="' + obj.id + '"><label for="' + randomId + '">' + obj.label + '</label>';
        }

        function getLabelSelector(id) {
            return 'label[for="' + id + '"]';
        }

        function getTreeView(objects) {
            let ul, li = '';
            for (let key in objects) {
                let obj = objects[key];
                li += '<li>';
                li += getView(obj);
                if (obj.children) {
                    li += getTreeView(obj.children);
                }
                li += '</li>';
            }
            if (li) {
                ul = '<ul>' + li + '</ul>'
            }
            return ul || '';
        }

        function generateNewObjectId() {
            return getRandomInteger();
        }

        function renderDatabaseTree(objectsTree) {
            databaseTreeView.innerHTML = getTreeView(objectsTree);
        }

        function renderCacheTree(objectsTree) {
            cacheTreeView.innerHTML = getTreeView(objectsTree);
        }

        function clearCache() {
            for (let key in cacheObjectsProxy) {
                delete cacheObjectsProxy[key];
            }
        }

        function isEmpty(obj) {
            return !Object.keys(obj).length;
        }

        /**
         * Return cloned objects as tree
         *
         * @param {object} objectsObj
         * @param {number|null} rootId, must be != 0
         * @return {object}
         */
        function getObjectsTree(objectsObj, rootId = null) {

            let objects = _.cloneDeep(objectsObj);
            let objectsBranch = {};
            let removeKeys = [];
            for (let key in objects) {
                let obj = objects[key];
                obj.children = {};

                // build tree
                if (obj.parent_id in objects) {
                    let parentObj = objects[obj.parent_id];
                    parentObj.children[obj.id] = obj;
                    removeKeys.push(key);
                } else {
                    objects[obj.id] = obj;
                }

                // cut branch from tree
                if (rootId !== null && obj.id === rootId) {
                    objectsBranch = {
                        [obj.id]: obj
                    };
                }
            }

            if (rootId !== null) {
                return objectsBranch;
            }

            removeKeys.forEach((key) => {
                delete objects[key];
            });

            return objects;
        }

        function getDatabaseObjectProxy(target) {
            return new Proxy(target, {
                set(objects, key, obj) {
                    objects[key] = obj;
                    renderDatabaseTree(getObjectsTree(objects));
                }
            });
        }

        function getCacheObjectsProxy(target) {
            return new Proxy(target, {
                set(objects, key, obj) {
                    objects[key] = obj;
                    renderCacheTree(getObjectsTree(objects));
                },
                deleteProperty(objects, key) {
                    delete objects[key];
                    renderCacheTree(getObjectsTree(objects));
                },
            });
        }

        function processTree(objectsTree, callback) {
            for (let key in objectsTree) {
                let obj = objectsTree[key];
                if (!isEmpty(obj.children)) processTree(obj.children, callback);
                callback(obj);
            }
        }

        /**
         *  Entity init
         */

        // layouts
        let databaseTreeView = document.getElementById('databaseTreeView');
        let cacheTreeView = document.getElementById('cacheTreeView');
        // controls
        let copyDatabaseObjectInCacheButton = document.getElementById('copyDatabaseObjectInCacheButton');
        let renameCacheObjectButton = document.getElementById('renameCacheObjectButton');
        let createCacheObjectButton = document.getElementById('createCacheObjectButton');
        let removeCacheObjectButton = document.getElementById('removeCacheObjectButton');
        let applyChangesButton = document.getElementById('applyChangesButton');
        let resetButton = document.getElementById('resetButton');
        // layout selectors
        let itemSelector = 'input[name="item"]:checked';
        // models
        let databaseObjects = _.cloneDeep(sourceDataBaseObjects);
        let databaseObjectsTree = getObjectsTree(databaseObjects);
        let databaseObjectsProxy = getDatabaseObjectProxy(databaseObjects);
        let cacheObjects = {};
        let cacheObjectsProxy = getCacheObjectsProxy(cacheObjects);
        let cacheDatabaseObjects = {};

        /**
         * User action events (controllers)
         */

        copyDatabaseObjectInCacheButton.onclick = () => {
            let selectedInput = databaseTreeView.querySelector(itemSelector);
            if (!selectedInput) {
                alert('Please select item');
                return false;
            }
            let databaseObject = databaseObjects[selectedInput.value];
            cacheObjectsProxy[databaseObject.id] = databaseObject;

            // Mark all the child elements as delete if parent has been deleted independent on cache tree that you can see
            for (let key in cacheObjects) {
                let obj = cacheObjects[key];
                if (obj.is_deleted) {
                    // take database objects independent on cache tree
                    let databaseObjectsTree = getObjectsTree(databaseObjects, obj.id);
                    processTree(databaseObjectsTree, (obj) => {
                        // if object is in cache then mark as delete
                        if (cacheObjectsProxy[obj.id]) {
                            obj.is_deleted = true;
                            cacheObjectsProxy[obj.id] = obj;
                        }
                    });
                }
            }

            cacheDatabaseObjects = databaseObjects;
        }

        createCacheObjectButton.onclick = () => {
            let label = prompt('Label');
            if (!label) return false;

            let selectedInput = cacheTreeView.querySelector(itemSelector);
            let cacheObject = {
                id: generateNewObjectId(),
                parent_id: selectedInput ? parseInt(selectedInput.value) : null,
                is_deleted: false,
                label: label
            }
            cacheObjectsProxy[cacheObject.id] = cacheObject;
        }

        renameCacheObjectButton.onclick = () => {
            let selectedInput = cacheTreeView.querySelector(itemSelector);
            if (!selectedInput) {
                alert('Please select item');
                return false;
            }
            let labelTag = cacheTreeView.querySelector(getLabelSelector(selectedInput.id));
            let editInput = document.createElement('input');
            editInput.value = labelTag.innerHTML;
            editInput.onkeydown = (event) => {
                if (event.key === 'Enter') this.blur();
            }
            editInput.onblur = () => {
                let labelValue = editInput.value;
                if (!labelValue) {
                    alert('Please input label');
                    return false;
                }
                let object = cacheObjectsProxy[selectedInput.value];
                object.label = labelValue;
                cacheObjectsProxy[object.id] = object;
            }
            labelTag.replaceWith(editInput);
            editInput.focus();
        }

        removeCacheObjectButton.onclick = () => {
            let selectedInput = cacheTreeView.querySelector(itemSelector);
            if (!selectedInput) {
                alert('Please select item');
                return false;
            }

            let databaseObjectsTree = getObjectsTree(cacheDatabaseObjects, parseInt(selectedInput.value));
            processTree(databaseObjectsTree, (obj) => {
                if (cacheObjects[obj.id]) {
                    obj.is_deleted = true;
                    cacheObjectsProxy[obj.id] = obj;
                }
            });

            let cacheObjectsTree = getObjectsTree(cacheObjects, parseInt(selectedInput.value));
            processTree(cacheObjectsTree, (obj) => {
                if (!cacheDatabaseObjects[obj.id]) {
                    delete cacheObjectsProxy[obj.id];
                }
            });
        }

        applyChangesButton.onclick = () => {

            for (let key in cacheObjects) {
                let cacheObject = cacheObjects[key];

                if (cacheObject.id in databaseObjects) { // update label, mark as removed...
                    let databaseObject = databaseObjects[cacheObject.id];
                    databaseObject.label = cacheObject.label;

                    if (cacheObject.is_deleted) {
                        databaseObject.is_deleted = cacheObject.is_deleted;
                        let databaseObjectsTree = getObjectsTree(databaseObjects, databaseObject.id);
                        processTree(databaseObjectsTree, (obj) => {
                            obj.is_deleted = true;
                            databaseObjectsProxy[obj.id] = obj;
                        });
                    }
                    databaseObjectsProxy[cacheObject.id] = databaseObject;

                } else { // insert
                    databaseObjectsProxy[cacheObject.id] = cacheObject;
                }
            }
        }

        resetButton.onclick = () => {
            clearCache();
            databaseObjects = _.cloneDeep(sourceDataBaseObjects);
            databaseObjectsTree = getObjectsTree(databaseObjects);
            databaseObjectsProxy = getDatabaseObjectProxy(databaseObjects);
            renderDatabaseTree(databaseObjectsTree);
        }

        /**
         * Run Application
         */

        renderDatabaseTree(databaseObjectsTree);

    </script>
    <br><br>

</div>
</body>
</html>