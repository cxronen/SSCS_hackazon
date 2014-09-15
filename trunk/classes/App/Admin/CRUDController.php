<?php
/**
 * Created by IntelliJ IDEA.
 * User: Nikolay Chervyakov 
 * Date: 28.08.2014
 * Time: 19:42
 */


namespace App\Admin;
use App\Exception\NotFoundException;
use App\Model\BaseModel;
use PHPixie\ORM\Model;
use PHPixie\Paginate;


/**
 * Controller which provides basic CRUD features for Model instances.
 * For detailed tuning of features, just derive fom it and override methods.
 * @package App\Admin
 */
class CRUDController extends Controller
{
    /**
     * @var string Plural name of the model to be shown in UI.
     */
    public $modelNamePlural = '';

    /**
     * @var string Singular name of the model
     */
    public $modelName = '';

    protected $modelFields;

    public $listView = 'crud/list';

    public $editView = 'crud/edit';

    /**
     * @var array|null
     */
    protected $preparedEditFields;

    public function before()
    {
        parent::before();

        if (!$this->modelName) {
            $this->modelName = $this->get_real_class($this);;
        }

        if (!$this->modelNamePlural) {
            $this->modelNamePlural = $this->modelName . 's';
        }

        $this->view->pageTitle = $this->modelNamePlural;
        $this->view->pageHeader = $this->modelNamePlural;

        $this->prepareModelFields();
    }

    /**
     * List items.
     */
    public function action_index()
    {
        $listFields = $this->prepareListFields();

        if ($this->request->is_ajax()) {
            $perPage = $this->request->get('length', 10);
            if ($perPage < 1 || $perPage > 100) {
                $perPage = 10;
            }
            $start = $this->request->get('start', 0);
            if ($start < 0) {
                $start = 0;
            }

            $page = floor($start / $perPage) + 1;
            if ($page < 1) {
                $page = 1;
            }

            $columns = $this->request->get('columns', []);
            $this->tuneModelForList();

            $totalCount = $this->model->count_all();
            $this->model->prepare_relations();

            // Set ordering
            $order = $this->request->get('order', [['column' => 0, 'dir' => 'asc']]);
            $order = $order[0];
            $orderColumn = $columns[$order['column']] ?: [];
            $orderColumn = $orderColumn['data'] ?: key($listFields);
            if ($listFields[$orderColumn]['extra']) {
                foreach ($listFields as $lKey => $lValue) {
                    if (!$lValue['extra'] && $lValue['orderable']) {
                        $orderColumn = $lKey;
                        break;
                    }
                }
            }

            if (strpos($orderColumn, '___') === false) {
                $this->model->order_by($orderColumn, $order['dir'] ? : 'asc');
            } else {
                $orderColumn = str_replace('___', '.', $orderColumn);
                $this->model->order_by($orderColumn, $order['dir'] ? : 'asc');
            }

            // Set filtering
            $search = $this->request->get('search', ['value' => '']);
            $searchValue = $search['value'];
            $searchValues = preg_split('/\s+/', $searchValue, -1, PREG_SPLIT_NO_EMPTY);

            if ($searchValues) {
                $searchConditions = [];
                foreach ($listFields as $lf => $lfData) {
                    if (!$lfData['searching']) {
                        continue;
                    }
                    $fieldSearchConditions = [];
                    foreach ($searchValues as $sVal) {
                        if (!is_numeric($sVal) && $lfData['data_type'] == 'integer' ) {
                            continue;
                        }
                        $fieldSearchConditions[] = ['and', [str_replace('___', '.', $lf), 'LIKE', "%$sVal%"]];
                    }
                    if ($fieldSearchConditions) {
                        $searchConditions[] = ['or', $fieldSearchConditions];
                    }
                }
                if ($searchConditions) {
                    $this->model->where('and', $searchConditions);
                }
            }

            // Query for items
            $items = $this->pixie->paginate->orm($this->model, $page, $perPage);
            $result = [
                'data' => $this->filterPaginator($items, $listFields),
                'recordsTotal' => (int) $totalCount,
                'recordsFiltered' => (int) $items->num_items
            ];

            $this->jsonResponse($result);
            return;

        } else {
            $this->view->subview = $this->listView;
            $this->view->listFields = $listFields;
            $this->view->modelName = $this->model->model_name;
        }
    }

    /**
     * Must be overridden in child classes to fit the instance model to query (add relations and so on)
     */
    protected function tuneModelForList()
    {
    }

    /**
     * Edit existing item
     */
    public function action_edit()
    {
        $id = $this->request->param('id');

        if ($this->request->method == 'POST') {
            $item = null;
            if ($id) {
                /** @var BaseModel $item */
                $item = $this->pixie->orm->get($this->model->model_name, $id);
            }

            if (!$item || !$item->loaded()) {
                throw new NotFoundException();
            }

            $data = $this->request->post();
            $this->processRequestFilesForItem($item, $data);
            $item->values($item->filterValues($data));
            $item->save();

            if ($item->loaded()) {
                $this->redirect('/admin/' . strtolower($item->model_name) . '/edit/'.$item->id());
                return;
            }

        } else {

            if (!$id) {
                throw new NotFoundException();
            }

            $item = $this->pixie->orm->get($this->model->model_name, $id);
            if (!$item || !$item->loaded()) {
                throw new NotFoundException();
            }
        }

        $editFields = $this->prepareEditFields();
        $this->view->pageTitle = $this->modelName;
        $this->view->pageHeader = $this->view->pageTitle;
        $this->view->modelName = $this->model->model_name;
        $this->view->item = $item;
        $this->view->editFields = $editFields;
        $this->view->formatter = new FieldFormatter($item, $editFields);
        $this->view->formatter->setPixie($this->pixie);
        $this->view->subview = $this->editView;
    }

    /**
     * Create new item
     */
    public function action_new()
    {
        /** @var BaseModel $item */
        $item = $this->pixie->orm->get($this->model->model_name);

        if ($this->request->method == 'POST') {
            $data = $this->request->post();
            $this->processRequestFilesForItem($item, $data);
            $item->values($item->filterValues($data));
            $item->save();

            if ($item->loaded()) {
                $this->redirect('/admin/' . strtolower($item->model_name) . '/edit/'.$item->id());
                return;
            }
        }

        $editFields = $this->prepareEditFields();
        $this->view->pageTitle = 'Add new ' . $this->modelName;
        $this->view->pageHeader = $this->view->pageTitle;
        $this->view->modelName = $this->model->model_name;
        $this->view->item = $item;
        $this->view->editFields = $editFields;
        $this->view->formatter = new FieldFormatter($item, $editFields);
        $this->view->formatter->setPixie($this->pixie);
        $this->view->subview = $this->editView;
    }

    public function action_delete()
    {
        if ($this->request->method != 'POST') {
            throw new NotFoundException();
        }

        $id = $this->request->param('id');
        if (!$id) {
            throw new NotFoundException();
        }

        $item = $this->pixie->orm->get($this->model->model_name, $id);

        if (!$item || !$item->loaded()) {
            throw new NotFoundException();
        }

        $item->delete();

        $location = '/admin/'.strtolower($this->model->model_name);

        if ($this->request->is_ajax()) {
            $this->jsonResponse(['success' => 1, 'location' => $location]);
        } else {
            $this->redirect($location);
        }
    }

    protected function prepareModelFields()
    {
        $this->modelFields = $this->model->columns();
    }

    /**
     * Meta information for fields in list. Can be overridden in child classes to describe fields more precisely.
     * @return array
     */
    protected function getListFields()
    {
        return array_merge(
            $this->getIdCheckboxProp(),
            array_combine($this->modelFields, array_fill(0, count($this->modelFields), [])),
            $this->getEditLinkProp(),
            $this->getDeleteLinkProp()
        );
    }

    /**
     * Prepares field meta information in a canonical form.
     * @return array
     */
    protected function prepareListFields()
    {
        $listFields = $this->getListFields();

        $result = [];
        foreach ($listFields as $field => &$data) {
            if (is_numeric($field) && is_string($data)) {
                $field = $data;
                $data = [];
            }

            $data['original_field_name'] = $field;

            if (!$data['type']) {
                $data['type'] = 'text';
            }

            if (!array_key_exists('title', $data) || $data['title'] === null) {
                $data['title'] = ucwords(implode(' ', preg_split('/_+/', $field, -1, PREG_SPLIT_NO_EMPTY)));
            }

            $this->checkSubProp($field, $data);

            if ($data['type'] == 'link' || $data['is_link']) {
                $data['is_link'] = true;
                if (!$data['template']) {
                    $data['template'] = '/admin/' . $this->model->model_name . '/edit/%' . $this->model->id_field . '%';
                }
            }

            if ($data['type'] == 'image') {
                if (!$data['max_width']) {
                    $data['max_width'] = 40;
                }

                if (!$data['max_height']) {
                    $data['max_height'] = 30;
                }

                if (!$data['dir_path']) {
                    $data['dir_path'] = '/images/';
                }

                if (!array_key_exists('orderable', $data)) {
                    $data['orderable'] = false;
                }

                if (!array_key_exists('searching', $data)) {
                    $data['searching'] = false;
                }
            }

            if ($data['extra']) {
                $data['orderable'] = false;
                $data['searching'] = false;
            }

            if (!array_key_exists('orderable', $data)) {
                $data['orderable'] = true;
            }

            if (!array_key_exists('searching', $data)) {
                $data['searching'] = true;
            }

            if (strpos($field, '.') !== false) {
                $field = $data['model'] . '___' . $data['model_prop'];
            }

            $result[$field] = $data;
        }
        $listFields = $result;
        unset($data);
        $listFields[$this->model->id_field]['type'] = 'link';
        $listFields[$this->model->id_field]['template'] = '/admin/'.$this->model->model_name.'/edit/%'.$this->model->id_field.'%';
        $listFields[$this->model->id_field]['width'] = '60';

        return $listFields;
    }

    protected function getEditFields()
    {

        return array_combine($this->modelFields, array_fill(0, count($this->modelFields), []));
    }

    protected function prepareEditFields()
    {
        if ($this->preparedEditFields) {
            return $this->preparedEditFields;
        }

        $editFields = $this->getEditFields();

        $result = [];
        foreach ($editFields as $field => &$data) {
            if (is_numeric($field) && is_string($data)) {
                $field = $data;
                $data = [];
            }

            $data['original_field_name'] = $field;

            if (!$data['type']) {
                $data['type'] = 'text';
            }

            if (!$data['label']) {
                $data['label'] = ucwords(implode(' ', preg_split('/_+/', $field, -1, PREG_SPLIT_NO_EMPTY)));
            }

            if ($data['select'] && !array_key_exists('multiple', $data)) {
                $data['multiple'] = false;
            }

            if ($data['type'] == 'image') {
                if (!$data['max_width']) {
                    $data['max_width'] = 400;
                }

                if (!$data['max_height']) {
                    $data['max_height'] = 300;
                }

                if (!$data['dir_path']) {
                    $data['dir_path'] = '/images/';
                }
            }

            if ($data['type'] == 'image' || $data['type'] == 'file') {
                if (!$data['dir_path']) {
                    $data['dir_path'] = '/upload/';
                }

                if (!array_key_exists('abs_path', $data)) {
                    $data['abs_path'] = false;
                }
            }

            $result[$field] = $data;
        }
        $editFields = $result;
        unset($data);

        $editFields[$this->model->id_field]['type'] = 'hidden';

        $this->preparedEditFields = $editFields;
        return $this->preparedEditFields;
    }

    /**
     * @param $items Paginate\Pager\ORM
     * @param $fields
     * @return array
     */
    private function filterPaginator($items, $fields)
    {
        /** @var BaseModel[] $data */
        $data = $items->current_items()->as_array();
        $result = [];
        foreach ($data as $item) {
            $resultItem = [];
            foreach ($fields as $field => $info) {
                if ($info['model']) {
                    $modelName = $info['model'];
                    $modelProp = $info['model_prop'];
                    $resultItem[$field] = $this->fieldFormatter($item->$modelName->$modelProp, $item, $info);

                } else if (isset($item->$field)) {
                    $resultItem[$field] = $this->fieldFormatter($item->$field, $item, $info);
                } else if ($info['extra']) {
                    $resultItem[$field] = $this->fieldFormatter($item->id(), $item, $info);
                }
            }
            $result[] = $resultItem;
        }

        return $result;
    }

    /**
     * @param $value
     * @param $item Model
     * @param array $format
     * @return string
     */
    public function fieldFormatter($value, $item = null, array $format = [])
    {

        if ($format['max_length']) {
            $length = strlen($value);
            if ($length > $format['max_length']) {
                $value = substr($value, 0, $format['max_length']).'...';
            }
        }

        if ($format['strip_tags']) {
            $value = strip_tags($value);
        }

        $value = $format['value_prefix'] . $value;
        $value = htmlspecialchars($value);

        if ($format['type'] == 'image') {
            if ($value) {
                $value = '<img src="' . $format['dir_path'] . $value . '" style="max-width: ' . $format['max_width'] . 'px; '
                    . ' max-height: ' . $format['max_height'] . 'px;" />';
            } else {
                $value = '';
            }
        }

        if ($format['type'] == 'boolean') {
            $value = '<span class="fa-boolean fa fa-circle' . ($value ? '' : '-o') . '"></span>';
        }

        if ($format['type'] == 'html' && $format['template']) {
            $controller = $this;
            $value = preg_replace_callback('/%(.+?)%/', function ($match) use ($item, $controller, $value) {
                $prop = $match[1];
                $controller->checkSubProp($prop, $matches);
                if ($matches['model']) {
                    $model = $matches['model'];
                    $modelProp = $matches['model_prop'];
                    $propValue = $item->$model->$modelProp;

                } else {
                    $propValue = $item->$prop;
                }
                return $propValue;
            }, $format['template']);
        }

        $isLink = $format['type'] == 'link' || $format['is_link'];

        if ($isLink) {
            preg_match('/%(.+?)%/', $format['template'], $matches);
            $linkProp = $matches[1];
            if ($linkProp) {
                $this->checkSubProp($linkProp, $lpMatches);
                if ($lpMatches['model']) {
                    $lpModel = $lpMatches['model'];
                    $lpModelProp = $lpMatches['model_prop'];
                    $linkPropValue = $item->$lpModel->$lpModelProp;

                } else {
                    $linkPropValue = $item->$linkProp;
                }
                $value = '<a href="' . str_replace('%' . $linkProp . '%', $linkPropValue, $format['template']) . '">'
                    . ($format['text'] ?: $value) . '</a>';
            }
        }

        return $value;
    }

    public function checkSubProp($field, &$data)
    {
        if (strpos($field, '.') !== false) {
            preg_match('/^(?<model>[^\.]*)\.(?<prop>.*)/', $field, $matches);
            $data['model'] = $matches['model'];
            $data['model_prop'] = $matches['prop'];
        }
    }

    private function processRequestFilesForItem(BaseModel $item, array &$data = [])
    {
        $editFields = $this->prepareEditFields();
        foreach ($editFields as $field => $options) {
            if (!in_array($options['type'], ['image', 'file'])) {
                 continue;
            }

            $removeFieldName = 'remove_image_'.$field;
            if (array_key_exists($removeFieldName, $data)) {
                $this->removeExistingFile($item, $field, $options);
            }

            $file = $this->request->uploadedFile($field);
            if (!$file->isLoaded()) {
                continue;
            }
            $this->removeExistingFile($item, $field, $options);
            $fileName = $file->generateFileName($this->user->id());
            $dirPath = $options['abs_path']
                ? $options['dir_path'] : $this->pixie->root_dir.'web/'.preg_replace('|^/+|', '', $options['dir_path']);
            $destPath = $dirPath.$fileName;
            $file->move($destPath);
            $item->$field = $fileName;
        }
    }

    protected function removeExistingFile(BaseModel $item, $field, array $options)
    {
        if (!$item->id()) {
            return;
        }
        $existingFile = $item->$field;
        $absPath = $options['abs_path'] ? $options['dir_path']
            : $this->pixie->root_dir.'web/'.preg_replace('|^/+|', '', $options['dir_path']) . $existingFile;
        if (file_exists($absPath) && is_file($absPath) && is_writable($absPath)) {
            unlink($absPath);
        }
        $item->$field = '';
    }

    protected function getEditLinkProp()
    {
        return [
            'edit' => [
                'extra' => true,
                'type' => 'html',
                'template' => '<a href="/admin/'.strtolower($this->model->model_name).'/edit/%'.$this->model->id_field.'%" '
                    . ' class="js-edit-item">Edit</a>'
            ]
        ];
    }

    protected function getDeleteLinkProp() {
        return [
            'delete' => [
                'extra' => true,
                'type' => 'html',
                'template' => '<a href="/admin/'.strtolower($this->model->model_name).'/delete/%'.$this->model->id_field.'%" '
                    . ' class="js-delete-item">Delete</a>'
            ],
        ];
    }

    protected function getIdCheckboxProp()
    {
        return [
            'cb' => [
                'extra' => true,
                'template' => '<input type="checkbox" name="ids[]" value="%'.$this->model->id_field.'%" />',
                'title' => '',
                'type' => 'html'
            ]
        ];
    }
}