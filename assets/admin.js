(function () {
  var busy = false;

  function config() {
    return window.cdair || null;
  }

  function targetElement(target) {
    if (!target) {
      return null;
    }
    if (target.nodeType === 1) {
      return target;
    }
    return target.parentElement || null;
  }

  function post(action, id) {
    var cfg = config();
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce);
    if (id) {
      body.append("attachment_id", id);
    }
    return fetch(cfg.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    }).then(function (response) {
      return response.text();
    }).then(function (text) {
      try {
        return JSON.parse(text);
      } catch (error) {
        return { success: false, data: { message: cfg.failed } };
      }
    });
  }

  function messageOf(result) {
    var cfg = config();
    if (result && result.data && result.data.message) {
      return result.data.message;
    }
    return cfg ? cfg.failed : "The request failed.";
  }

  function poll(id, attempt) {
    var cfg = config();
    if (attempt > 40) {
      return Promise.resolve({
        success: false,
        data: { message: cfg.timeout },
      });
    }
    return post("cdair_poll", id).then(function (result) {
      if (!result || !result.success) {
        return result;
      }
      if (result.data && result.data.status === "processing") {
        return new Promise(function (resolve) {
          window.setTimeout(resolve, 3000);
        }).then(function () {
          return poll(id, attempt + 1);
        });
      }
      return result;
    });
  }

  function finish(button, label) {
    busy = false;
    if (!button) {
      return;
    }
    button.removeAttribute("aria-disabled");
    button.textContent = label;
  }

  window.addEventListener("click", function (event) {
    var cfg = config();
    var element = targetElement(event.target);
    if (!element || !element.closest) {
      return;
    }

    var test = element.closest("#cdair-test");
    if (test) {
      if (!cfg || typeof window.fetch !== "function") {
        return;
      }
      event.preventDefault();
      var resultBox = document.getElementById("cdair-test-result");
      test.disabled = true;
      if (resultBox) {
        resultBox.textContent = cfg.testing;
      }
      post("cdair_test").then(function (result) {
        if (resultBox) {
          resultBox.textContent = messageOf(result);
        }
        test.disabled = false;
      });
      return;
    }

    var button = element.closest("[data-cdair-action]");
    if (!button || busy) {
      return;
    }
    if (!cfg || typeof window.fetch !== "function") {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    var action = button.getAttribute("data-cdair-action");
    var id = button.getAttribute("data-id");
    var confirmText = action === "cdair_restore" ? cfg.confirmRestore : cfg.confirmReplace;
    if (!window.confirm(confirmText)) {
      return;
    }

    var label = button.textContent;
    busy = true;
    button.setAttribute("aria-disabled", "true");
    button.textContent = action === "cdair_restore" ? cfg.restoring : cfg.working;

    var request = action === "cdair_restore" ? post("cdair_restore", id) : post("cdair_start", id).then(function (result) {
      if (!result || !result.success) {
        return result;
      }
      return poll(id, 0);
    });

    request.then(function (result) {
      if (!result || !result.success) {
        window.alert(messageOf(result));
        finish(button, label);
        return;
      }
      window.alert(messageOf(result));
      window.location.reload();
    }).catch(function () {
      window.alert(cfg.failed);
      finish(button, label);
    });
  }, true);
})();
