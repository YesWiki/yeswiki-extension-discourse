// The `discourse` field in the form builder: the forum category its topics go to, picked from the forum's own list.
const container = document.getElementById('form-builder-container')
let discourse = {}
try {
  discourse = JSON.parse(container?.dataset.extensions || '{}')?.discourse || {}
} catch {
  discourse = {}
}
const categories = discourse.categories || {}
const hasCategories = Object.keys(categories).length > 0
const categoryOptions = {
  '': _t('DISCOURSE_FORM_BUILDER_NO_CATEGORY'),
  ...categories,
}

window.formBuilderFields = window.formBuilderFields || {}
window.formBuilderFields.discourse = ({ readConf, writeConf }) => ({
  field: {
    label: _t('DISCOURSE_FORM_BUILDER_LABEL'),
    name: 'discourse',
    attrs: { type: 'discourse' },
    icon: '<svg class="yw-icon" aria-hidden="true"><use href="src/assets/icons.svg#message-circle"/></svg>',
  },
  defaultIdentifier: 'bf_discourse',
  editorHint: discourse.configured
    ? _t('DISCOURSE_FORM_BUILDER_HINT')
    : _t('DISCOURSE_FORM_BUILDER_NOT_CONFIGURED'),
  attributes: {
    category: hasCategories
      ? {
          label: _t('DISCOURSE_FORM_BUILDER_CATEGORY'),
          options: categoryOptions,
          description: _t('DISCOURSE_FORM_BUILDER_CATEGORY_HINT'),
        }
      : {
          label: _t('DISCOURSE_FORM_BUILDER_CATEGORY'),
          type: 'number',
          value: '',
          description: _t('DISCOURSE_FORM_BUILDER_CATEGORY_ID_HINT'),
        },
    hint: { label: _t('BAZ_FORM_EDIT_HELP'), value: '' },
    read_access: readConf,
    write_access: writeConf,
  },
  disabledAttributes: ['default', 'required'],
  advancedAttributes: ['read_access', 'write_access'],
  editorSetup(api) {
    if (hasCategories) api.setOptions('category', categoryOptions)
  },
})
