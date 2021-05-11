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
    <script src="/quant/funcs.js"></script>
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
</div>

<div class="row mt-4" style="font-size: 12px;">
    <div class="col-4">Cache:
        <pre id="cacheLog"></pre>
    </div>
    <div class="col-4">Manager:
        <pre id="managerLog"></pre>
    </div>
    <div class="col-4">Database:
        <pre id="databaseLog"></pre>
    </div>
</div>
<script>

    /**
     * Functions
     */

    function getView(obj) {
        let randomId = getRandomString();
        if (obj.is_deleted) {
            return '<span>' + obj.label + '</span>'
        }
        return '<input id="' + randomId + '" type="radio" name="item" value="' + obj.id + '"><label for="' + randomId + '">' + obj.label + '</label>';
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

    function generateCacheObjectId() {
        return -getRandomInteger();
    }

    function isNewCacheObject(obj) {
        return obj.id < 0;
    }

    function clearCacheId(id){
        return id ? Math.abs(id) : null;
    }

    function getLabelSelector(id) {
        return 'label[for="' + id + '"]';
    }

    function renderDatabaseTree(objectsTree) {
        databaseTreeView.innerHTML = getTreeView(objectsTree);
    }

    function renderCacheTree(objectsTree) {
        cacheTreeView.innerHTML = getTreeView(objectsTree);
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
                if (!parentObj.hasOwnProperty('children')) parentObj.children = {};
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
            get(objects, key) {
                this.setChildIds(objects, objects[key]);
                return objects[key];
            },
            set(objects, key, obj) {
                objects[key] = obj;
                renderDatabaseTree(getObjectsTree(objects));
                return true;
            },
            setChildIds: (objects, targetObj) => {
                idsManager.initRelation(targetObj);
                processTree(getObjectsTree(objects, targetObj.id), (obj) => {
                    if (targetObj.id != obj.id) {
                        idsManager.addChildId(targetObj.id, obj.id);
                    }
                });
            },
        });
    }

    function getCacheObjectsProxy(target) {
        return new Proxy(target, {
            set(objects, key, obj) {
                objects[key] = obj;
                renderCacheTree(getObjectsTree(objects));
                this.setChildIds(obj);
                return true;
            },
            deleteProperty(objects, key) {
                let obj = objects[key];
                delete objects[key];
                renderCacheTree(getObjectsTree(objects));

                idsManager.removeChildIdFromParent(obj);
                idsManager.removeRelation(obj.id);
            },
            setChildIds: (obj) => {
                if (isNewCacheObject(obj)) {
                    idsManager.initRelation(obj);
                    idsManager.addChildId(obj.parent_id, obj.id);
                }
            }
        });
    }

    function processTree(objectsTree, callback) {
        for (let key in objectsTree) {
            let obj = objectsTree[key];
            if (!isEmpty(obj.children)) processTree(obj.children, callback);
            callback(obj);
        }
    }

    function removeCacheObject(obj) {
        if (isNewCacheObject(obj)) {
            delete cacheObjectsProxy[obj.id];
        } else {
            obj.is_deleted = true;
            cacheObjectsProxy[obj.id] = obj;
        }
    }

    /**
     *  Entities
     */

    class IdsManager {
        map = {};
        constructor() {}
        initRelation(obj) {
            this.map[obj.id] = {
                'id': obj.id,
                'parent_id': obj.parent_id,
                'childIds': []
            }
        }
        addRelation(key, obj) {
            this.map[key] = obj;
            return this;
        }
        getRelation(key) {
            if (!this.map.hasOwnProperty(key)) {
                console.error('Key ' +  key + ' not found');
                return false;
            }
            return this.map[key];
        }
        addChildId(key, id) {
            if (!this.map.hasOwnProperty(key)) {
                console.error('Key ' +  key + ' not found');
                return false;
            }
            this.map[key].childIds.push(id);
        }
        getChildIds(key) {
            if (!this.map.hasOwnProperty(key)) {
                console.error('Key ' +  key + ' not found');
                return [];
            }
            return this.map[key].childIds;
        }
        removeChildIdFromParent(obj) {
            let key = obj.parent_id;
            if (!this.map.hasOwnProperty(key)) {
                console.error('Key ' +  key + ' not found');
                return false;
            }
            this.map[key].childIds = this.map[key].childIds.filter(childId => childId != obj.id);
        }
        removeRelation(id) {
            delete this.map[id];
        }
        removeAll() {
            this.map = {};
        }
        markRelationAsProd(key) {
            if (!this.map.hasOwnProperty(key)) {
                console.error('Key ' +  key + ' not found');
                return false;
            }
            let relation = this.getRelation(key);
            relation.id = clearCacheId(relation.id);
            relation.parent_id = clearCacheId(relation.parent_id);
            relation.childIds = relation.childIds.map(id => clearCacheId(id));
            this.removeRelation(key);
            this.addRelation(relation.id, relation);
        }
        getAll() { //temp to logging
            return this.map;
        }
    }
    let idsManager = new IdsManager();

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
    // entities
    let databaseObjects = _.cloneDeep(sourceDataBaseObjects);
    let databaseObjectsTree = getObjectsTree(databaseObjects);
    let databaseObjectsProxy = getDatabaseObjectProxy(databaseObjects);
    let cacheObjects = {};
    let cacheObjectsProxy = getCacheObjectsProxy(cacheObjects);

    /**
     * User action events
     */

    copyDatabaseObjectInCacheButton.onclick = () => {
        let selectedInput = databaseTreeView.querySelector(itemSelector);
        if (!selectedInput) {
            alert('Please select item');
            return false;
        }
        let databaseObject = databaseObjectsProxy[selectedInput.value];
        cacheObjectsProxy[databaseObject.id] = databaseObject;

        // Mark all the child elements as delete in cache if parent has been deleted independent on cache tree that you can see
        for (let key in cacheObjects) {
            let cacheObject = cacheObjects[key];
            if (cacheObject.is_deleted) {
                let databaseObjectsTree = getObjectsTree(databaseObjects, cacheObject.id);
                processTree(databaseObjectsTree, (databaseObject) => {
                    let childCacheObject = cacheObjects[databaseObject.id];
                    // if object is in cache then mark as delete
                    if (childCacheObject) {
                        childCacheObject.is_deleted = true;
                        cacheObjectsProxy[childCacheObject.id] = childCacheObject;
                    }
                });
            }
        }
    }

    createCacheObjectButton.onclick = () => {
        let selectedInput = cacheTreeView.querySelector(itemSelector);
        if (!selectedInput) {
            alert('Please select item');
            return false;
        }

        let label = prompt('Label');
        if (!label) return false;

        let cacheObject = {
            id: generateCacheObjectId(),
            parent_id: parseInt(selectedInput.value),
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
            let object = cacheObjects[selectedInput.value];
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

        function processChildren(ids) {
            ids.forEach(id => {
                let obj = cacheObjects[id];
                obj.is_deleted = true;
                let childIds = idsManager.getChildIds(obj.id);
                if (childIds) {
                    processChildren(childIds);
                }
                // process leaf
                removeCacheObject(obj);
            });
        }
        let cacheObject = cacheObjects[selectedInput.value];
        let childIds = idsManager.getChildIds(cacheObject.id);
        processChildren(childIds);
        removeCacheObject(cacheObject);
    }

    applyChangesButton.onclick = () => {

        for (let key in cacheObjects) {
            let cacheObject = cacheObjects[key];

            cacheObject.id = clearCacheId(cacheObject.id);
            cacheObject.parent_id = clearCacheId(cacheObject.parent_id);

            if (cacheObject.id in databaseObjects) { // update label, mark as removed...
                let databaseObject = databaseObjects[cacheObject.id];
                databaseObject.label = cacheObject.label;
                databaseObject.is_deleted = cacheObject.is_deleted;
                if (databaseObject.is_deleted) {
                    // mark as deleted all the children
                    let databaseObjectsTree = getObjectsTree(databaseObjects, databaseObject.id);
                    processTree(databaseObjectsTree, (childObj) => {
                        let obj = databaseObjects[childObj.id];
                        obj.is_deleted = true;
                        databaseObjectsProxy[obj.id] = obj;
                    });
                }
                databaseObjectsProxy[cacheObject.id] = databaseObject;
            } else { // insert
                databaseObjectsProxy[cacheObject.id] = cacheObject;
            }
            idsManager.markRelationAsProd(key);

            delete cacheObjects[key];
            cacheObjectsProxy[cacheObject.id] = cacheObject;
        }
    }

    resetButton.onclick = () => {

        idsManager.removeAll();
        cacheObjects = {};
        cacheObjectsProxy = getCacheObjectsProxy(cacheObjects);
        renderCacheTree(cacheObjects);

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
</body>
</html>