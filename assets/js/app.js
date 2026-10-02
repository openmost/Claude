(function () {
  var OVERLAY_HOST_ID = 'ai-chat-insight-overlay-host-claude';

  // A single insights panel for the whole page, every report trigger drives it through a shared store
  function mountInsightOverlay() {
    if (document.getElementById(OVERLAY_HOST_ID)) {
      return;
    }

    var host = document.createElement('div');
    host.id = OVERLAY_HOST_ID;
    host.setAttribute('vue-entry', 'Claude.InsightOverlay');
    host.setAttribute('ai-name', 'claude');
    host.setAttribute('ai-label', 'Claude');
    host.setAttribute('ai-color', '#D97757');
    host.setAttribute('api-method', 'Claude.getInsights');
    document.body.appendChild(host);

    piwikHelper.compileVueEntryComponents(host);
  }

  window.addEventListener('widget:loaded', function (e) {
    var parameters = e.detail[0].parameters;
    var element = e.detail[0].element[0];
    // Matomo 5 report headers have no toolbar, the trigger floats at the right of the title
    var titleWrapper = element.querySelector('.enrichedHeadline');

    if (!titleWrapper) {
      return;
    }

    // the enriched headline also holds the help and feedback texts, only its .title is the name
    var titleElement = element.querySelector('.enrichedHeadline .title')
      || element.querySelector('.widgetName');
    var reportTitle = titleElement ? titleElement.textContent.trim() : '';

    mountInsightOverlay();

    var insightTrigger = document.createElement('div');
    insightTrigger.classList.add('ai-chat-insight-trigger-vue-wrapper');
    insightTrigger.setAttribute('vue-entry', 'Claude.InsightTrigger');
    insightTrigger.setAttribute('widget-params', JSON.stringify(parameters));
    insightTrigger.setAttribute('ai-name', 'claude');
    insightTrigger.setAttribute('ai-label', 'Claude');
    insightTrigger.setAttribute('ai-color', '#D97757');
    insightTrigger.setAttribute('report-title', reportTitle);
    titleWrapper.append(insightTrigger);

    piwikHelper.compileVueEntryComponents(insightTrigger);
  });
})();
