// The `discourse` field in the form builder: the category, tags and first post of the topics it opens, from the forum's own lists.
const container = document.getElementById('form-builder-container')
let discourse = {}
try {
  discourse = JSON.parse(container?.dataset.extensions || '{}')?.discourse || {}
} catch {
  discourse = {}
}
const categories = discourse.categories || {}
const tags = Array.isArray(discourse.tags) ? discourse.tags : []
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
    tags: {
      label: _t('DISCOURSE_FORM_BUILDER_TAGS'),
      value: '',
      placeholder: _t('DISCOURSE_FORM_BUILDER_TAGS_PLACEHOLDER'),
      description:
        tags.length > 0
          ? _t('DISCOURSE_FORM_BUILDER_TAGS_EXISTING', {
              tags: tags.join(', '),
            })
          : _t('DISCOURSE_FORM_BUILDER_TAGS_HINT'),
    },
    topic_body: {
      label: _t('DISCOURSE_FORM_BUILDER_TOPIC_BODY'),
      type: 'textarea',
      rows: 5,
      value: '',
      description: _t('DISCOURSE_FORM_BUILDER_TOPIC_BODY_HINT'),
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
