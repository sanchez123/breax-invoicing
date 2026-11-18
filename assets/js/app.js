(function () {
  "use strict";

  var validationErrorId = 0;

  function initSidebar() {
    var sidebar = document.getElementById("sidebar");
    var scrim = document.getElementById("scrim");
    var toggle = document.querySelector("[data-sidebar-toggle]");
    if (!sidebar || !scrim || !toggle) return;

    function setOpen(open) {
      sidebar.classList.toggle("is-open", open);
      scrim.classList.toggle("is-visible", open);
      document.body.classList.toggle("is-locked", open);
      toggle.setAttribute("aria-expanded", String(open));
      if (!open && sidebar.contains(document.activeElement)) toggle.focus();
    }

    toggle.addEventListener("click", function () {
      setOpen(!sidebar.classList.contains("is-open"));
    });
    document.querySelectorAll("[data-sidebar-close]").forEach(function (element) {
      element.addEventListener("click", function () { setOpen(false); });
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") setOpen(false);
    });
    sidebar.querySelectorAll(".nav__link").forEach(function (link) {
      link.addEventListener("click", function () { setOpen(false); });
    });
  }

  function initMenus() {
    var toggles = document.querySelectorAll("[data-menu-toggle]");
    function closeMenus(except) {
      toggles.forEach(function (button) {
        var menu = document.getElementById(button.getAttribute("data-menu-toggle"));
        if (!menu || menu === except) return;
        menu.hidden = true;
        button.setAttribute("aria-expanded", "false");
      });
    }
    toggles.forEach(function (button) {
      var menu = document.getElementById(button.getAttribute("data-menu-toggle"));
      if (!menu) return;
      button.addEventListener("click", function (event) {
        event.stopPropagation();
        var open = menu.hidden;
        closeMenus(menu);
        menu.hidden = !open;
        button.setAttribute("aria-expanded", String(open));
      });
    });
    document.addEventListener("click", function (event) {
      if (!event.target.closest(".menu-wrap")) closeMenus();
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeMenus();
    });
  }

  function initConfirmDialogs() {
    var dialog = document.getElementById("confirm-dialog");
    var panel = dialog && dialog.querySelector(".confirm-dialog__panel");
    var title = document.getElementById("confirm-dialog-title");
    var message = document.getElementById("confirm-dialog-message");
    var accept = document.getElementById("confirm-dialog-accept");
    if (!dialog || !panel || !title || !message || !accept) return;

    var pendingForm = null;
    var pendingSubmitter = null;
    var previousFocus = null;

    function closeDialog() {
      dialog.hidden = true;
      document.body.classList.remove("is-locked");
      pendingForm = null;
      pendingSubmitter = null;
      if (previousFocus && typeof previousFocus.focus === "function") previousFocus.focus();
      previousFocus = null;
    }

    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-confirm")) return;
      if (form.dataset.confirmed === "true") {
        delete form.dataset.confirmed;
        if (form.dataset.confirmedInput) {
          var confirmedInput = form.elements.namedItem(form.dataset.confirmedInput);
          if (confirmedInput instanceof HTMLInputElement) confirmedInput.value = "1";
        }
        if (form.hasAttribute("data-disable-on-submit")) form.dataset.confirmApproved = "true";
        return;
      }
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter || null;
      previousFocus = document.activeElement;
      title.textContent = form.dataset.confirmTitle || "Please confirm";
      message.textContent = form.dataset.confirm || "Continue with this action?";
      accept.textContent = form.dataset.confirmAcceptLabel || "Yes";
      var cancel = dialog.querySelector(".confirm-dialog__actions [data-confirm-cancel]");
      if (cancel) cancel.textContent = form.dataset.confirmCancelLabel || "No";
      accept.classList.toggle("confirm-dialog__confirm--danger", form.dataset.confirmDanger === "true");
      accept.classList.toggle("btn--primary", form.dataset.confirmDanger !== "true");
      accept.classList.toggle("btn--danger", form.dataset.confirmActionStyle === "danger");
      dialog.hidden = false;
      document.body.classList.add("is-locked");
      accept.focus();
    }, true);

    accept.addEventListener("click", function () {
      if (!pendingForm) return;
      var form = pendingForm;
      var submitter = pendingSubmitter;
      closeDialog();
      form.dataset.confirmed = "true";
      if (typeof form.requestSubmit === "function") {
        if (submitter) form.requestSubmit(submitter);
        else form.requestSubmit();
      } else {
        form.submit();
      }
    });

    dialog.querySelectorAll("[data-confirm-cancel]").forEach(function (control) {
      control.addEventListener("click", closeDialog);
    });
    document.addEventListener("keydown", function (event) {
      if (dialog.hidden) return;
      if (event.key === "Escape") {
        event.preventDefault();
        closeDialog();
      } else if (event.key === "Tab") {
        event.preventDefault();
        (document.activeElement === accept ? dialog.querySelector("[data-confirm-cancel]:not(.confirm-dialog__backdrop)") : accept).focus();
      }
    });
  }

  function initInvoiceEmailToolbar() {
    document.querySelectorAll("form[data-disable-on-submit]").forEach(function (form) {
      form.addEventListener("submit", function () {
        if (form.dataset.customValidation === "true") return;
        if (form.hasAttribute("data-confirm") && form.dataset.confirmApproved !== "true") return;
        delete form.dataset.confirmApproved;
        disableFormSubmitButton(form);
      });
    });
  }

  function initInvoiceBulkDelete() {
    var form = document.getElementById("delete-selected-invoices");
    if (!form) return;
    var selections = Array.from(form.querySelectorAll("[data-invoice-select]"));
    var selectAll = form.querySelector("[data-invoice-select-all]");
    var deleteButton = form.querySelector("[data-invoice-bulk-delete]");
    var count = form.querySelector("[data-invoice-selection-count]");
    if (!deleteButton || !count) return;

    function updateSelection() {
      var selectedCount = selections.filter(function (checkbox) { return checkbox.checked; }).length;
      deleteButton.disabled = selectedCount === 0;
      count.textContent = String(selectedCount);
      if (selectAll) {
        selectAll.checked = selections.length > 0 && selectedCount === selections.length;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < selections.length;
      }
    }

    selections.forEach(function (checkbox) {
      checkbox.addEventListener("change", updateSelection);
    });
    if (selectAll) {
      selectAll.addEventListener("change", function () {
        selections.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        updateSelection();
      });
    }
    updateSelection();
  }

  function disableFormSubmitButton(form) {
    var button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.textContent = button.dataset.submittingLabel || "Sending...";
  }

  function initOverdueAdminCheck() {
    var endpoint = document.body.dataset.overdueAdminCheck;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!endpoint || !csrf) return;
    fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf.content }
    }).catch(function () {
      // The server logs delivery errors; this request must never block page use.
    });
  }

  function initAdminAlertStatus() {
    var row = document.querySelector("[data-admin-alert-status]");
    if (!row) return;

    var endpoint = row.dataset.endpoint;
    var button = row.querySelector("[data-admin-alert-send]");
    var badge = row.querySelector("[data-admin-alert-badge]");
    var icon = row.querySelector("[data-admin-alert-icon]");
    var helper = row.querySelector("[data-admin-alert-helper]");
    var feedback = row.querySelector("[data-admin-alert-feedback]");
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!endpoint || !button || !badge || !icon || !helper || !feedback || !csrf) return;

    var sent = row.dataset.sent === "true";
    var sending = false;

    function updateState(isSent) {
      sent = isSent;
      row.dataset.sent = String(sent);
      row.classList.toggle("is-sent", sent);
      badge.classList.toggle("is-sent", sent);
      icon.classList.toggle("is-sent", sent);
      badge.textContent = sent ? "Sent" : "Not sent";
      helper.textContent = sent
        ? "Alert was sent. You can send it again."
        : "Alerts are sent automatically. Nothing for you to do.";
      button.disabled = sending || !sent;
      button.title = sent ? "Send this alert again" : "Alerts are sent automatically";
      if (sending || !sent) button.setAttribute("aria-disabled", "true");
      else button.removeAttribute("aria-disabled");
    }

    function showFeedback(message, isError) {
      feedback.textContent = message;
      feedback.hidden = message === "";
      feedback.classList.toggle("is-error", isError);
      feedback.classList.toggle("notice--error", isError);
      feedback.classList.toggle("notice--success", message !== "" && !isError);
      feedback.setAttribute("role", isError ? "alert" : "status");
      feedback.setAttribute("aria-live", isError ? "assertive" : "polite");
    }

    function refreshStatus() {
      return fetch(endpoint, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" }
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || typeof data.sent !== "boolean") {
            throw new Error(data.message || "The alert status could not be checked.");
          }
          updateState(data.sent);
        });
      }).catch(function () {});
    }

    button.addEventListener("click", function () {
      if (!sent || sending) return;

      sending = true;
      showFeedback("", false);
      button.textContent = "Sending…";
      button.setAttribute("aria-busy", "true");
      updateState(sent);

      fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-CSRF-Token": csrf.content
        },
        body: new URLSearchParams({ action: "resend" })
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) throw new Error(data.message || "The alert could not be sent.");
          showFeedback(data.message || "Alert sent to all opted-in administrators.", false);
        });
      }).catch(function (error) {
        showFeedback(error.message || "The alert could not be sent. Check the invoice email log and settings.", true);
      }).finally(function () {
        sending = false;
        button.textContent = "Send alert";
        button.removeAttribute("aria-busy");
        updateState(sent);
        refreshStatus();
      });
    });

    refreshStatus();
    window.setInterval(function () {
      if (!document.hidden) refreshStatus();
    }, 10000);
  }

  function initPasswordToggle() {
    var button = document.querySelector("[data-toggle-password]");
    var password = document.getElementById("password");
    if (!button || !password) return;
    button.addEventListener("click", function () {
      var visible = password.type === "password";
      password.type = visible ? "text" : "password";
      button.setAttribute("aria-pressed", String(visible));
      button.setAttribute("aria-label", visible ? "Hide password" : "Show password");
      var icon = button.querySelector("use");
      if (icon) icon.setAttribute("href", visible ? "#i-eye-off" : "#i-eye");
    });
  }

  function initCustomerFlash() {
    document.querySelectorAll(".notice--customer").forEach(function (notice) {
      var dismiss = notice.querySelector("[data-dismiss-notice]");
      if (dismiss) dismiss.addEventListener("click", function () { notice.remove(); });
    });
    if (document.querySelector(".notice--customer")) window.scrollTo(0, 0);
    document.addEventListener("submit", function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-customer-form")) return;
      window.setTimeout(function () {
        if (!event.defaultPrevented || (form.hasAttribute("data-confirm") && form.dataset.confirmApproved !== "true")) return;
        var errorSummary = document.querySelector("[data-customer-error-summary]");
        if (!errorSummary) return;
        errorSummary.textContent = "Please fix the highlighted fields and try again.";
        errorSummary.hidden = false;
        errorSummary.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 0);
    }, true);
  }

  function initCustomerDomains() {
    var form = document.querySelector("[data-customer-form]");
    var list = form && form.querySelector("[data-customer-domain-list]");
    var template = form && form.querySelector("[data-customer-domain-template]");
    var addButton = form && form.querySelector("[data-add-customer-domain]");
    var section = form && form.querySelector("[data-customer-domain-section]");
    var domainsSection = form && form.querySelector("#domains-section");
    var primaryBilling = form && form.querySelector("#primary-billing-address");
    var primarySection = form && form.querySelector("#primary-domain-section");
    var primaryDomainInput = form && form.querySelector("[data-primary-domain]");
    var primaryExpiryInput = form && form.querySelector("[data-primary-domain-expiry]");
    var summary = form && form.querySelector("[data-domain-validation-summary]");
    var countBadge = form && form.querySelector("[data-domain-count]");
    var primaryCountBadge = form && form.querySelector("[data-primary-domain-count]");
    var confirmationInput = form && form.querySelector('input[name="mode_change_confirmed"]');
    if (!form || !list || !template || !addButton || !section || !domainsSection || !primaryBilling || !primarySection || !primaryDomainInput || !primaryExpiryInput) return;

    var nextIndex = 0;
    var checkTimers = new WeakMap();
    var checkVersions = new WeakMap();
    var remoteErrors = new WeakMap();
    var serverErrors = new WeakMap();
    var touched = new WeakSet();
    var primaryTouched = new WeakSet();
    var submitted = false;
    var primaryDomainRemoteError = "";
    var primaryDomainCheckTimer = null;
    var primaryDomainCheckVersion = 0;
    list.querySelectorAll('[name^="domains["]').forEach(function (input) {
      var match = input.name.match(/^domains\[(\d+)\]/);
      if (match) nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
      var error = input.closest(".field") && input.closest(".field").querySelector("[data-error-for]");
      if (error && error.textContent.trim()) {
        touched.add(input);
        serverErrors.set(input, error.textContent.trim());
      }
    });

    function domainDeliverySelected() {
      var selected = form.querySelector('input[name="invoice_delivery"]:checked');
      return selected && selected.value === "domain";
    }

    function normalizeDomain(value) {
      var domain = value.trim().toLowerCase();
      if (!domain) return "";
      if (/[^\x00-\x7F]/.test(domain)) return "";
      if (/^[a-z][a-z0-9+.-]*:\/\//i.test(domain)) {
        try { domain = new URL(domain).hostname; } catch (ignore) { return ""; }
      } else {
        domain = domain.split(/[/?#]/, 1)[0];
      }
      domain = domain.replace(/\.+$/, "");
      while (domain.indexOf("www.") === 0) domain = domain.slice(4);
      return domain;
    }

    function validDomain(value) {
      if (/[\/:?#\s]/.test(value.trim())) return false;
      var domain = normalizeDomain(value);
      if (!domain || domain.length > 253 || domain.indexOf(".") === -1) return false;
      var labels = domain.split(".");
      return labels.every(function (label) {
        return label.length > 0 && label.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label);
      }) && /^[a-z0-9-]{2,63}$/.test(labels[labels.length - 1]);
    }

    function validPrimaryDomain(value) {
      var trimmed = value.trim();
      if (!trimmed || /[\/:?#\s]/.test(trimmed) || /[^\x00-\x7F]/.test(trimmed)) return false;
      var domain = normalizeDomain(trimmed);
      if (!domain || domain.length > 253 || domain.indexOf(".") === -1) return false;
      var labels = domain.split(".");
      return labels.every(function (label) {
        return label.length > 0 && label.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label);
      }) && /^[a-z]{2,63}$/.test(labels[labels.length - 1]);
    }

    function setError(input, selector, message) {
      if (!input) return;
      var field = input.closest(".field") || input.parentElement;
      var error = field && field.querySelector(selector);
      if (!error) return;
      error.textContent = message;
      error.hidden = message === "";
      input.setCustomValidity("");
      input.classList.toggle("is-invalid", Boolean(message));
      if (message) input.setAttribute("aria-invalid", "true");
      else input.removeAttribute("aria-invalid");
    }

    function validatePrimary(force) {
      if (domainDeliverySelected()) return null;
      var firstInvalid = null;
      [[primaryDomainInput, "primary_domain"], [primaryExpiryInput, "primary_domain_expiry"]].forEach(function (entry) {
        var input = entry[0];
        if (!force && !primaryTouched.has(input)) return;
        var message = "";
        if (input === primaryDomainInput) {
          var value = input.value.trim();
          if (!value) message = "Enter the domain";
          else if (!validPrimaryDomain(value)) message = "Enter a valid domain, e.g. example.co.ke";
          else message = primaryDomainRemoteError;
        } else if (!input.value) {
          message = "Select the expiry date";
        } else {
          var parsedDate = new Date(input.value + "T00:00:00Z");
          if (input.validity.badInput || !/^\d{4}-\d{2}-\d{2}$/.test(input.value)
              || Number.isNaN(parsedDate.getTime()) || parsedDate.toISOString().slice(0, 10) !== input.value) {
            message = "Enter a valid date";
          }
        }
        setError(input, '[data-error-for="' + entry[1] + '"]', message);
        if (message && !firstInvalid) firstInvalid = input;
      });
      return firstInvalid;
    }

    function checkPrimaryDomainDuplicate() {
      primaryDomainCheckVersion += 1;
      var version = primaryDomainCheckVersion;
      if (primaryDomainCheckTimer) window.clearTimeout(primaryDomainCheckTimer);
      primaryDomainRemoteError = "";
      validatePrimary(false);
      if (domainDeliverySelected() || !validPrimaryDomain(primaryDomainInput.value)) return;
      var domainValue = normalizeDomain(primaryDomainInput.value);
      primaryDomainCheckTimer = window.setTimeout(function () {
        var params = new URLSearchParams();
        params.set("kind", "primary_domain");
        params.set("domain", domainValue);
        params.set("customer_id", form.querySelector('input[name="id"]').value || "0");
        fetch(form.dataset.domainDuplicateCheckUrl + "?" + params.toString(), {
          credentials: "same-origin",
          headers: { "Accept": "application/json" }
        }).then(function (response) {
          if (!response.ok) throw new Error("Duplicate check failed.");
          return response.json();
        }).then(function (result) {
          if (version !== primaryDomainCheckVersion || primaryDomainInput.disabled
              || normalizeDomain(primaryDomainInput.value) !== domainValue) return;
          primaryDomainRemoteError = result.domain_error || "";
          validatePrimary(false);
        }).catch(function () {
          if (version === primaryDomainCheckVersion) {
            primaryDomainRemoteError = "Could not verify whether this domain is already in use. Check your connection and try again.";
            validatePrimary(false);
          }
        });
      }, 200);
    }

    function messageFor(input, rows, remote, duplicates) {
      if (input.matches("[data-domain-company]")) {
        var companyName = input.value.trim();
        return companyName.length < 2 || companyName.length > 150
          ? "Company name must be between 2 and 150 characters."
          : "";
      }
      if (input.matches("[data-domain-phone]")) {
        var phone = input.value.trim();
        if (!phone) return "Enter the phone number";
        if (phone.length > 20 || !/^[0-9+\s()-]+$/.test(phone)) return "Enter a valid phone number";
        var normalizedPhone = phone.replace(/[\s()-]+/g, "");
        return /^\+?\d{9,15}$/.test(normalizedPhone) ? "" : "Enter a valid phone number";
      }
      if (input.matches("[data-domain-name]")) {
        var value = input.value.trim();
        if (!value) return "Domain is required.";
        if (!validDomain(value)) return "Enter a valid domain, for example example.co.ke.";
        if (duplicates.has(normalizeDomain(value))) return "This domain is already added for this customer.";
        return remote.domain || "";
      }
      if (input.matches("[data-domain-expiry]")) {
        if (!input.value) return "Expiry date is required.";
        return input.validity.badInput || Number.isNaN(Date.parse(input.value)) ? "Enter a valid expiry date." : "";
      }
      if (input.matches("[data-domain-email]")) {
        if (!domainDeliverySelected()) return "";
        var email = input.value.trim();
        if (!email) return "Invoice email is required.";
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return "Enter a valid email address.";
        var localEmailDuplicates = rows.filter(function (row) {
          var field = row.querySelector("[data-domain-email]");
          return field && field.value.trim().toLowerCase() === email.toLowerCase();
        }).length > 1;
        return localEmailDuplicates
          ? "This invoice email is repeated in another row. Use a unique email address for each domain."
          : remote.email || "";
      }
      if (input.matches("[data-domain-billing]")) {
        var billingAddress = input.value.trim();
        return billingAddress.length < 5 || billingAddress.length > 500
          ? "Billing address must be between 5 and 500 characters."
          : "";
      }
      return "";
    }

    function validateRows(force) {
      if (!domainDeliverySelected()) {
        if (summary) summary.hidden = true;
        return null;
      }
      var rows = Array.from(list.querySelectorAll("[data-customer-domain-row]"));
      var domainValues = new Map();
      rows.forEach(function (row) {
        var domain = row.querySelector("[data-domain-name]");
        var value = domain && validDomain(domain.value) ? normalizeDomain(domain.value) : "";
        if (value) domainValues.set(value, (domainValues.get(value) || 0) + 1);
      });
      var firstInvalid = null;
      rows.forEach(function (row) {
        var remote = remoteErrors.get(row) || { domain: "", email: "" };
        row.querySelectorAll("[data-domain-company], [data-domain-phone], [data-domain-name], [data-domain-expiry], [data-domain-email], [data-domain-billing]").forEach(function (input) {
          var showError = force || submitted || touched.has(input);
          if (!showError) return;
          var duplicates = new Set();
          domainValues.forEach(function (count, value) {
            if (count > 1) duplicates.add(value);
          });
          var message = messageFor(input, rows, remote, duplicates) || serverErrors.get(input) || "";
          var errorSelector = input.matches("[data-domain-company]") ? '[data-error-for="company_name"]'
            : input.matches("[data-domain-phone]") ? '[data-error-for="phone_number"]'
            : input.matches("[data-domain-name]") ? '[data-error-for="domain"]'
              : input.matches("[data-domain-expiry]") ? '[data-error-for="expiry_date"]'
                : input.matches("[data-domain-email]") ? '[data-error-for="invoice_email"]'
                  : '[data-error-for="billing_address"]';
          setError(input, errorSelector, message);
          if (message && !firstInvalid) firstInvalid = input;
        });
      });
      if (summary && rows.length > 0 && !firstInvalid) summary.hidden = true;
      return firstInvalid;
    }

    function updateMode() {
      var domainMode = !!domainDeliverySelected();
      var rowCount = list.querySelectorAll("[data-customer-domain-row]").length;
      section.classList.toggle("dm--domain", domainMode);
      domainsSection.hidden = !domainMode;
      domainsSection.disabled = !domainMode;
      domainsSection.querySelectorAll("input, textarea").forEach(function (control) {
        control.disabled = !domainMode;
      });
      primarySection.hidden = domainMode;
      primarySection.querySelectorAll("input, textarea").forEach(function (control) {
        control.disabled = domainMode;
      });
      primaryBilling.hidden = domainMode;
      primaryBilling.querySelectorAll("textarea").forEach(function (control) {
        control.disabled = domainMode;
      });
      section.querySelectorAll("[data-domain-email-required]").forEach(function (mark) {
        mark.hidden = !domainMode;
      });
      section.querySelectorAll("[data-domain-email]").forEach(function (email) {
        email.required = domainMode;
      });
      if (countBadge) countBadge.textContent = String(rowCount);
      if (primaryCountBadge) primaryCountBadge.textContent = domainMode ? "0" : "1";
      if (confirmationInput) confirmationInput.value = "";
      form.removeAttribute("data-confirm");
      delete form.dataset.confirmedInput;
      var selectedMode = domainMode ? "domain" : "primary";
      var savedMode = form.dataset.savedMode || "";
      if (selectedMode !== savedMode) {
        var savedDomainCount = Number(form.dataset.savedDomainCount || "0");
        var hasSavedPrimaryDomain = form.dataset.savedPrimaryDomain === "true";
        if (savedMode === "domain" && selectedMode === "primary" && savedDomainCount > 0) {
          var domainNoun = savedDomainCount === 1 ? "domain" : "domains";
          form.dataset.confirm = "Switching to Primary email will permanently delete this customer's " + savedDomainCount + " saved " + domainNoun + " and their company details. Continue?";
        } else if (savedMode === "primary" && selectedMode === "domain" && hasSavedPrimaryDomain) {
          form.dataset.confirm = "Switching to Domain email will remove the saved business domain and expiry date. Continue?";
        }
        if (form.hasAttribute("data-confirm")) {
          form.dataset.confirmTitle = "Confirm invoice email mode change";
          form.dataset.confirmAcceptLabel = "Delete and save";
          form.dataset.confirmCancelLabel = "Cancel";
          form.dataset.confirmDanger = "true";
          form.dataset.confirmActionStyle = "danger";
          form.dataset.confirmedInput = "mode_change_confirmed";
        }
      }
      validateRows(false);
      validatePrimary(false);
    }

    function renumberRows() {
      list.querySelectorAll("[data-customer-domain-row]").forEach(function (row, index) {
        row.querySelectorAll("[name^='domains[']").forEach(function (control) {
          control.name = control.name.replace(/^domains\[\d+\]/, "domains[" + index + "]");
        });
        var rowNumber = index + 1;
        var numberBadge = row.querySelector("[data-row-number]");
        if (numberBadge) numberBadge.textContent = String(rowNumber);
        var removeButton = row.querySelector("[data-remove-customer-domain]");
        if (removeButton) {
          removeButton.setAttribute("aria-label", "Remove domain " + rowNumber);
          removeButton.title = "Remove domain " + rowNumber;
        }
      });
      nextIndex = list.querySelectorAll("[data-customer-domain-row]").length;
    }

    function checkSavedDuplicates(row) {
      var domain = row.querySelector("[data-domain-name]");
      var email = row.querySelector("[data-domain-email]");
      var id = row.querySelector('input[name$="[id]"]');
      if (!domain || !email) return;
      var version = (checkVersions.get(row) || 0) + 1;
      checkVersions.set(row, version);
      var timer = checkTimers.get(row);
      if (timer) window.clearTimeout(timer);

      var domainValue = validDomain(domain.value) ? normalizeDomain(domain.value) : "";
      var emailValue = domainDeliverySelected() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())
        ? email.value.trim()
        : "";
      var remote = { domain: "", email: "" };
      remoteErrors.set(row, remote);
      validateRows(false);
      if (!domainValue && !emailValue) {
        return;
      }
      timer = window.setTimeout(function () {
        var params = new URLSearchParams();
        params.set("domain", domainValue);
        params.set("email", /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue) ? emailValue : "");
        params.set("domain_id", id ? id.value : "");
        params.set("customer_id", form.querySelector('input[name="id"]').value || "0");
        fetch(form.dataset.domainDuplicateCheckUrl + "?" + params.toString(), {
          credentials: "same-origin",
          headers: { "Accept": "application/json" }
        }).then(function (response) {
          if (!response.ok) throw new Error("Duplicate check failed.");
          return response.json();
        }).then(function (result) {
          if (checkVersions.get(row) !== version || !list.contains(row)
              || (validDomain(domain.value) ? normalizeDomain(domain.value) : "") !== domainValue
              || (domainDeliverySelected() && email.value.trim() || "") !== emailValue) return;
          remoteErrors.set(row, { domain: result.domain_error || "", email: result.email_error || "" });
          validateRows(false);
        }).catch(function () {
          if (checkVersions.get(row) === version) {
            var message = "Could not verify whether this value is already in use. Check your connection and try again.";
            remoteErrors.set(row, {
              domain: domainValue ? message : "",
              email: emailValue ? message : ""
            });
            validateRows(false);
          }
        });
      }, 250);
      checkTimers.set(row, timer);
    }

    list.addEventListener("click", function (event) {
      var button = event.target.closest("[data-remove-customer-domain]");
      if (!button) return;
      var usedCount = Number(button.dataset.usedInvoices || "0");
      if (usedCount > 0 && !window.confirm("This domain is used by " + usedCount + " invoices. It will be archived, and those invoices will keep it.")) {
        return;
      }
      var removedRow = button.closest("[data-customer-domain-row]");
      checkVersions.set(removedRow, (checkVersions.get(removedRow) || 0) + 1);
      var timer = checkTimers.get(removedRow);
      if (timer) window.clearTimeout(timer);
      removedRow.remove();
      renumberRows();
      updateMode();
      var nextDomain = list.querySelector("[data-domain-name]");
      (nextDomain || addButton).focus();
    });
    list.addEventListener("input", function (event) {
      var input = event.target;
      if (!input.matches("[data-domain-company], [data-domain-phone], [data-domain-name], [data-domain-expiry], [data-domain-email], [data-domain-billing]")) return;
      serverErrors.delete(input);
      var error = input.closest(".field") && input.closest(".field").querySelector("[data-error-for]");
      if (error) error.textContent = "";
      if (touched.has(input) || submitted) validateRows(false);
      if (input.matches("[data-domain-name], [data-domain-email]")) {
        checkSavedDuplicates(input.closest("[data-customer-domain-row]"));
      }
    });
    list.addEventListener("change", function (event) {
      var input = event.target;
      if (input.matches("[data-domain-company], [data-domain-phone], [data-domain-name], [data-domain-expiry], [data-domain-email], [data-domain-billing]")) {
        serverErrors.delete(input);
        if (touched.has(input) || submitted) validateRows(false);
        if (input.matches("[data-domain-name], [data-domain-email]")) checkSavedDuplicates(input.closest("[data-customer-domain-row]"));
      }
    });
    list.addEventListener("focusout", function (event) {
      var input = event.target;
      if (!input.matches("[data-domain-company], [data-domain-phone], [data-domain-name], [data-domain-expiry], [data-domain-email], [data-domain-billing]")) return;
      touched.add(input);
      validateRows(false);
    });
    form.querySelectorAll('input[name="invoice_delivery"]').forEach(function (radio) {
      radio.addEventListener("change", function () {
        updateMode();
        list.querySelectorAll("[data-customer-domain-row]").forEach(checkSavedDuplicates);
      });
    });
    [primaryDomainInput, primaryExpiryInput].forEach(function (input) {
      input.addEventListener("input", function () {
        primaryTouched.add(input);
        if (primaryCountBadge) primaryCountBadge.textContent = "1";
        if (input === primaryDomainInput) checkPrimaryDomainDuplicate();
        else validatePrimary(false);
      });
      input.addEventListener("change", function () {
        primaryTouched.add(input);
        if (primaryCountBadge) primaryCountBadge.textContent = "1";
        if (input === primaryDomainInput) checkPrimaryDomainDuplicate();
        else validatePrimary(false);
      });
      input.addEventListener("blur", function () {
        primaryTouched.add(input);
        validatePrimary(false);
        if (input === primaryDomainInput) checkPrimaryDomainDuplicate();
      });
    });
    addButton.addEventListener("click", function () {
      var rowMarkup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
      var wrapper = document.createElement("div");
      wrapper.innerHTML = rowMarkup;
      var row = wrapper.firstElementChild;
      if (!row) return;
      list.appendChild(row);
      renumberRows();
      updateMode();
      row.querySelector("[data-domain-name]").focus();
    });
    form.addEventListener("submit", function (event) {
      submitted = true;
      var firstPrimaryInvalid = validatePrimary(true);
      var firstInvalid = validateRows(true);
      var hasRows = list.querySelectorAll("[data-customer-domain-row]").length > 0;
      if (!domainDeliverySelected() && firstPrimaryInvalid) {
        event.preventDefault();
        firstPrimaryInvalid.focus();
        return;
      }
      if (domainDeliverySelected() && (!hasRows || firstInvalid)) {
        event.preventDefault();
        if (summary) {
          summary.textContent = hasRows
            ? "Complete each domain's company, phone number, domain, expiry date, invoice email, and billing address before saving."
            : "Add at least one complete domain row before saving in Domain email mode.";
          summary.hidden = false;
          summary.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
        var focusTarget = firstInvalid || addButton;
        focusTarget.focus();
        focusTarget.scrollIntoView({ behavior: "smooth", block: "center" });
      } else if (summary) {
        summary.hidden = true;
      }
    });
    form.querySelectorAll("[data-restore-domain]").forEach(function (checkbox) {
      checkbox.addEventListener("change", function () {
        var label = checkbox.closest("label").querySelector("[data-restore-label]");
        if (label) label.textContent = checkbox.checked ? "Will restore on save" : "Restore";
      });
    });
    updateMode();
    if (primaryDomainInput.getAttribute("aria-invalid") === "true") primaryTouched.add(primaryDomainInput);
    if (primaryExpiryInput.getAttribute("aria-invalid") === "true") primaryTouched.add(primaryExpiryInput);
    checkPrimaryDomainDuplicate();
    if (domainDeliverySelected() && summary && !summary.hidden) {
      var firstServerInvalid = list.querySelector(".input.is-invalid");
      var focusTarget = firstServerInvalid || addButton;
      focusTarget.focus();
      focusTarget.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    list.querySelectorAll("[data-customer-domain-row]").forEach(function (row) {
      remoteErrors.set(row, { domain: "", email: "" });
      checkSavedDuplicates(row);
    });
  }

  function initCustomerGoogleWorkspaces() {
    var form = document.querySelector("[data-customer-form]");
    var list = form && form.querySelector("[data-customer-workspace-list]");
    var template = form && form.querySelector("[data-customer-workspace-template]");
    var addButton = form && form.querySelector("[data-add-customer-workspace]");
    var countBadge = form && form.querySelector("[data-workspace-count]");
    if (!form || !list || !template || !addButton) return;

    var nextIndex = 0;
    var remoteErrors = new WeakMap();
    var touched = new WeakSet();
    var timers = new WeakMap();
    var submitted = false;
    list.querySelectorAll('[name^="google_workspaces["]').forEach(function (input) {
      var match = input.name.match(/^google_workspaces\[(\d+)\]/);
      if (match) nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
      if (input.matches("[data-workspace-domain], [data-workspace-expiry]")
          && input.getAttribute("aria-invalid") === "true") touched.add(input);
    });

    function normalizeDomain(value) {
      var domain = value.trim().toLowerCase();
      if (!domain) return "";
      if (/[^\x00-\x7F]/.test(domain)) return "";
      if (/^[a-z][a-z0-9+.-]*:\/\//i.test(domain)) {
        try { domain = new URL(domain).hostname; } catch (ignore) { return ""; }
      } else {
        domain = domain.split(/[/?#]/, 1)[0];
      }
      domain = domain.replace(/\.+$/, "");
      while (domain.indexOf("www.") === 0) domain = domain.slice(4);
      return domain;
    }

    function validDomain(value) {
      var domain = normalizeDomain(value);
      if (!domain || domain.length > 253 || domain.indexOf(".") === -1) return false;
      var labels = domain.split(".");
      return labels.every(function (label) {
        return label.length > 0 && label.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label);
      }) && /^[a-z0-9-]{2,63}$/.test(labels[labels.length - 1]);
    }

    function updateError(input, selector, message) {
      var error = input.parentElement && input.parentElement.querySelector(selector);
      if (!error) return;
      error.textContent = message;
      error.hidden = message === "";
      input.setCustomValidity(message);
      if (message) input.setAttribute("aria-invalid", "true");
      else input.removeAttribute("aria-invalid");
    }

    function rows() {
      return Array.from(list.querySelectorAll("[data-customer-workspace-row]"));
    }

    function validate(force) {
      var rowList = rows();
      var counts = new Map();
      rowList.forEach(function (row) {
        var input = row.querySelector("[data-workspace-domain]");
        var value = input && validDomain(input.value) ? normalizeDomain(input.value) : "";
        if (value) counts.set(value, (counts.get(value) || 0) + 1);
      });
      var firstInvalid = null;
      rowList.forEach(function (row) {
        var domain = row.querySelector("[data-workspace-domain]");
        var expiry = row.querySelector("[data-workspace-expiry]");
        [domain, expiry].forEach(function (input) {
          if (!input || (!force && !submitted && !touched.has(input))) return;
          var message = "";
          if (input === domain) {
            var normalized = normalizeDomain(input.value);
            if (!input.value.trim()) message = "Domain is required.";
            else if (!validDomain(input.value)) message = "Enter a valid domain, for example example.co.ke.";
            else if ((counts.get(normalized) || 0) > 1) message = "This domain is already added under Google Workspace.";
            else message = (remoteErrors.get(domain) || "");
            updateError(input, "[data-workspace-domain-error]", message);
          } else {
            message = input.value ? "" : "Expiry date is required.";
            updateError(input, "[data-workspace-expiry-error]", message);
          }
          if (message && !firstInvalid) firstInvalid = input;
        });
      });
      if (countBadge) countBadge.textContent = String(rowList.length);
      return firstInvalid;
    }

    function checkSavedDuplicate(input) {
      var normalized = validDomain(input.value) ? normalizeDomain(input.value) : "";
      remoteErrors.set(input, "");
      if (!normalized) {
        validate(false);
        return;
      }
      var oldTimer = timers.get(input);
      if (oldTimer) window.clearTimeout(oldTimer);
      timers.set(input, window.setTimeout(function () {
        var id = input.closest("[data-customer-workspace-row]").querySelector('input[name$="[id]"]');
        var params = new URLSearchParams();
        params.set("domain", normalized);
        params.set("kind", "google_workspace");
        params.set("domain_id", id ? id.value : "");
        fetch(form.dataset.workspaceDuplicateCheckUrl + "?" + params.toString(), {
          credentials: "same-origin",
          headers: { "Accept": "application/json" }
        }).then(function (response) {
          if (!response.ok) throw new Error("Duplicate check failed.");
          return response.json();
        }).then(function (result) {
          if (!list.contains(input) || normalizeDomain(input.value) !== normalized) return;
          remoteErrors.set(input, result.domain_error || "");
          validate(false);
        }).catch(function () {
          if (list.contains(input) && normalizeDomain(input.value) === normalized) {
            remoteErrors.set(input, "Could not verify whether this domain is already in use. Check your connection and try again.");
            validate(false);
          }
        });
      }, 250));
    }

    list.addEventListener("click", function (event) {
      var button = event.target.closest("[data-remove-customer-workspace]");
      if (!button) return;
      button.closest("[data-customer-workspace-row]").remove();
      validate(true);
      var nextDomain = list.querySelector("[data-workspace-domain]");
      (nextDomain || addButton).focus();
    });
    list.addEventListener("input", function (event) {
      var input = event.target;
      if (!input.matches("[data-workspace-domain], [data-workspace-expiry]")) return;
      if (input.matches("[data-workspace-domain]")) {
        remoteErrors.set(input, "");
        checkSavedDuplicate(input);
      }
      if (submitted || touched.has(input)) validate(false);
    });
    list.addEventListener("change", function (event) {
      var input = event.target;
      if (!input.matches("[data-workspace-domain], [data-workspace-expiry]")) return;
      if (input.matches("[data-workspace-domain]")) checkSavedDuplicate(input);
      if (submitted || touched.has(input)) validate(false);
    });
    list.addEventListener("focusout", function (event) {
      var input = event.target;
      if (!input.matches("[data-workspace-domain], [data-workspace-expiry]")) return;
      touched.add(input);
      validate(false);
    });
    addButton.addEventListener("click", function () {
      var wrapper = document.createElement("div");
      wrapper.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
      var row = wrapper.firstElementChild;
      if (!row) return;
      list.appendChild(row);
      validate(false);
      row.querySelector("[data-workspace-domain]").focus();
    });
    form.addEventListener("submit", function (event) {
      submitted = true;
      var firstInvalid = validate(true);
      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus();
        firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    });
    rows().forEach(function (row) {
      var domain = row.querySelector("[data-workspace-domain]");
      remoteErrors.set(domain, "");
      checkSavedDuplicate(domain);
    });
  }

  function initInvoiceCustomerDomains() {
    var form = document.getElementById("invoice-form");
    var customer = document.getElementById("invoice-customer");
    var customerSearch = form && form.querySelector("#invoice-customer-search");
    var customerOptions = form && form.querySelector("#invoice-customer-options");
    var line = form && form.querySelector("[data-invoice-recipient-line]");
    var recipientValue = form && form.querySelector("[data-invoice-recipient-value]");
    var error = form && form.querySelector("[data-invoice-recipient-error]");
    var domainNameLine = form && form.querySelector("[data-invoice-domain-name]");
    var domainNameValue = form && form.querySelector("[data-invoice-domain-value]");
    var domainDetails = form && form.querySelector("[data-invoice-domain-details]");
    var domainCompanyName = form && form.querySelector("[data-invoice-company-name]");
    var domainBillingAddress = form && form.querySelector("[data-invoice-billing-address]");
    var picker = form && form.querySelector("[data-invoice-domain-picker]");
    var label = form && form.querySelector("[data-invoice-domain-label]");
    var domainSelect = form && form.querySelector("[data-invoice-domain-select]");
    var domainSearch = form && form.querySelector("[data-invoice-domain-search]");
    var issueNow = form && form.querySelector("[data-invoice-issue-now]");
    var domainPhoneNumber = form && form.querySelector("[data-invoice-domain-phone]");
    if (!form || !customer || !customerSearch || !customerOptions || !line || !recipientValue || !error || !domainNameLine || !domainNameValue || !domainDetails || !domainCompanyName || !domainBillingAddress || !domainPhoneNumber || !picker || !label || !domainSelect || !issueNow) return;

    var state = null;
    var initialDomainId = domainSelect.dataset.initialDomainId || "";
    var initialDomainName = domainNameValue.textContent;
    var highlightedCustomerIndex = -1;

    function customerMatches() {
      var query = customerSearch.value.trim().toLocaleLowerCase();
      return Array.from(customer.options).filter(function (option) {
        return option.value !== "" && option.textContent.toLocaleLowerCase().includes(query);
      });
    }

    function closeCustomerOptions() {
      customerOptions.hidden = true;
      customerSearch.setAttribute("aria-expanded", "false");
      customerSearch.removeAttribute("aria-activedescendant");
      highlightedCustomerIndex = -1;
    }

    function renderCustomerOptions() {
      var matches = customerMatches();
      customerOptions.replaceChildren();
      highlightedCustomerIndex = -1;
      if (!matches.length) {
        var empty = document.createElement("div");
        empty.className = "invoice-customer-options__empty";
        empty.textContent = "No customers match your search.";
        customerOptions.appendChild(empty);
      } else {
        matches.forEach(function (option, index) {
          var result = document.createElement("button");
          result.type = "button";
          result.className = "invoice-customer-option";
          result.id = "invoice-customer-option-" + option.value;
          result.setAttribute("role", "option");
          result.setAttribute("aria-selected", String(option.value === customer.value));
          result.textContent = option.textContent;
          result.addEventListener("click", function () {
            customer.value = option.value;
            customerSearch.value = option.textContent;
            customerSearch.setCustomValidity("");
            setFieldError(customerSearch, "");
            closeCustomerOptions();
            customer.dispatchEvent(new Event("change", { bubbles: true }));
          });
          result.dataset.optionIndex = String(index);
          customerOptions.appendChild(result);
        });
      }
      customerOptions.hidden = false;
      customerSearch.setAttribute("aria-expanded", "true");
    }

    function highlightCustomerOption(index) {
      var options = customerOptions.querySelectorAll('[role="option"]');
      if (!options.length) return;
      highlightedCustomerIndex = Math.max(0, Math.min(index, options.length - 1));
      options.forEach(function (option, optionIndex) {
        option.classList.toggle("is-active", optionIndex === highlightedCustomerIndex);
      });
      var activeOption = options[highlightedCustomerIndex];
      customerSearch.setAttribute("aria-activedescendant", activeOption.id);
      activeOption.scrollIntoView({ block: "nearest" });
    }

    var selectedCustomer = customer.options[customer.selectedIndex];
    if (selectedCustomer && selectedCustomer.value !== "") {
      customerSearch.value = selectedCustomer.textContent;
    }
    customerSearch.addEventListener("focus", renderCustomerOptions);
    customerSearch.addEventListener("input", function () {
      customer.value = "";
      customerSearch.setCustomValidity("");
      customer.dispatchEvent(new Event("change", { bubbles: true }));
      renderCustomerOptions();
    });
    customerSearch.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        if (customerOptions.hidden) renderCustomerOptions();
        highlightCustomerOption(highlightedCustomerIndex + 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        highlightCustomerOption(highlightedCustomerIndex - 1);
      } else if (event.key === "Enter" && !customerOptions.hidden) {
        var active = customerOptions.querySelectorAll('[role="option"]')[highlightedCustomerIndex];
        if (active) {
          event.preventDefault();
          active.click();
        }
      } else if (event.key === "Escape") {
        closeCustomerOptions();
      }
    });
    document.addEventListener("click", function (event) {
      if (!customerOptions.contains(event.target) && event.target !== customerSearch) closeCustomerOptions();
    });

    function showError(message) {
      error.textContent = message;
      error.hidden = message === "";
    }

    function selectedDomain() {
      var id = domainSelect.value;
      return state && state.domains.find(function (domain) { return String(domain.id) === id; });
    }

    function refreshRecipient() {
      if (!state) {
        picker.hidden = true;
        domainDetails.hidden = true;
        recipientValue.textContent = "—";
        return;
      }
      var domainMode = state.invoice_delivery === "domain";
      var domains = state.domains;
      picker.hidden = !domainMode && domains.length === 0;
      label.textContent = domainMode ? "Domain *" : "Domain (optional, for your records)";
      domainSelect.setAttribute("aria-required", String(domainMode));
      if (domainSearch) {
        domainSearch.hidden = domains.length < 8;
        domainSearch.value = "";
      }
      var domain = selectedDomain();
      var recipient = domainMode ? (domain ? String(domain.invoice_email || "").trim() : "") : String(state.primary_email || "").trim();
      domainDetails.hidden = !domainMode || !domain;
      domainCompanyName.textContent = domain ? String(domain.company_name || "") : "";
      domainBillingAddress.textContent = domain ? String(domain.billing_address || "") : "";
      var phoneNumber = domain ? String(domain.phone_number || "").trim() : "";
      domainPhoneNumber.textContent = phoneNumber;
      domainPhoneNumber.parentElement.hidden = phoneNumber === "";
      var displayDomain = domain ? domain.domain_name : initialDomainName;
      domainNameValue.textContent = displayDomain || "";
      domainNameValue.href = displayDomain ? "https://" + displayDomain : "#";
      domainNameLine.hidden = !displayDomain;
      recipientValue.textContent = recipient || "Recipient unavailable";
      if (!domainMode && !recipient) {
        showError("The customer has no valid primary email. Save as a draft or update the customer before issuing.");
      } else if (domainMode && !domain) {
        showError(domains.length === 0
          ? "This customer has no active domains. Add a domain before issuing an invoice."
          : "Choose a domain before issuing this invoice.");
      } else if (domainMode && (!recipient || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipient))) {
        showError("The selected domain has no valid invoice email. Save as a draft or update the domain before issuing.");
      } else if (recipient && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipient)) {
        showError("The customer's primary email is invalid. Save as a draft or update the customer before issuing.");
      } else {
        showError("");
      }
    }

    function loadCustomer(preserveDomain) {
      var customerId = customer.value;
      state = null;
      domainSelect.innerHTML = '<option value="">Choose a domain</option>';
      domainSelect.value = "";
      showError("");
      if (!customerId) {
        initialDomainName = "";
        domainNameValue.textContent = "";
        domainNameLine.hidden = true;
        refreshRecipient();
        return Promise.resolve();
      }
      var url = form.dataset.customerDomainsUrl + "?customer_id=" + encodeURIComponent(customerId);
      return fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
        .then(function (response) {
          if (!response.ok) throw new Error("Customer details could not be loaded.");
          return response.json();
        })
        .then(function (data) {
          if (!Array.isArray(data.domains)) throw new Error("Customer domain details are invalid.");
          state = data;
          data.domains.forEach(function (domain) {
            var option = document.createElement("option");
            option.value = String(domain.id);
            option.textContent = domain.domain_name;
            option.dataset.email = domain.invoice_email || "";
            domainSelect.appendChild(option);
          });
          var desired = preserveDomain ? initialDomainId : "";
          if (desired && data.domains.some(function (domain) { return String(domain.id) === desired; })) {
            domainSelect.value = desired;
          } else if (data.invoice_delivery === "domain" && data.domains.length === 1) {
            domainSelect.value = String(data.domains[0].id);
          }
          if (domainSelect.value) {
            var selected = data.domains.find(function (domain) { return String(domain.id) === domainSelect.value; });
            if (selected) initialDomainName = selected.domain_name;
          }
          initialDomainId = "";
          refreshRecipient();
        })
        .catch(function () {
          state = null;
          refreshRecipient();
          showError("Customer invoice settings could not be loaded. Reload the page before issuing this invoice.");
        });
    }

    customer.addEventListener("change", function () {
      initialDomainId = "";
      initialDomainName = "";
      loadCustomer(false);
    });
    domainSelect.addEventListener("change", refreshRecipient);
    if (domainSearch) {
      domainSearch.addEventListener("input", function () {
        var query = domainSearch.value.trim().toLowerCase();
        Array.from(domainSelect.options).forEach(function (option) {
          option.hidden = option.value !== "" && !option.textContent.toLowerCase().includes(query);
        });
      });
    }
    form.addEventListener("submit", function (event) {
      if (!customer.value) {
        event.preventDefault();
        customerSearch.setCustomValidity("Select a customer from the search results.");
        customerSearch.reportValidity();
        return;
      }
      customerSearch.setCustomValidity("");
      if (issueNow.checked && error.textContent.trim() !== "") {
        event.preventDefault();
        showError(error.textContent);
        error.scrollIntoView({ behavior: "smooth", block: "nearest" });
      }
    }, true);
    loadCustomer(true);
  }

  function initInvoiceItems() {
    var rows = document.getElementById("invoice-items");
    var addButton = document.getElementById("add-invoice-item");
    if (!rows || !addButton) return;
    var nextRowId = 1;

    function bindRow(row) {
      row.querySelector(".item-product").addEventListener("change", function (event) {
        var option = event.target.selectedOptions[0];
        if (!option.value) return;
        row.querySelector(".item-description").value = option.dataset.name || "";
      });
      row.querySelector(".remove-item").addEventListener("click", function () {
        if (rows.querySelectorAll(".invoice-item-row").length > 1) {
          row.remove();
          rows.dispatchEvent(new Event("input", { bubbles: true }));
        }
      });
    }
    rows.querySelectorAll(".invoice-item-row").forEach(bindRow);
    addButton.addEventListener("click", function () {
      if (rows.querySelectorAll(".invoice-item-row").length >= 50) return;
      var row = rows.querySelector(".invoice-item-row").cloneNode(true);
      var rowId = "added-" + nextRowId++;
      [
        [".item-product", "product"],
        [".item-description", "description"],
        ['input[name="item_quantity[]"]', "quantity"],
        [".item-price", "price"]
      ].forEach(function (fieldDefinition) {
        var field = row.querySelector(fieldDefinition[0]);
        var label = field.parentElement.querySelector(".li-label");
        field.id = "invoice-item-" + fieldDefinition[1] + "-" + rowId;
        if (label) label.htmlFor = field.id;
      });
      row.querySelectorAll(".field__error").forEach(function (error) { error.remove(); });
      row.querySelectorAll("[aria-invalid], [aria-describedby], [data-validation-touched]").forEach(function (control) {
        control.removeAttribute("aria-invalid");
        control.removeAttribute("aria-describedby");
        delete control.dataset.validationTouched;
      });
      row.querySelector(".item-product").selectedIndex = 0;
      row.querySelector(".item-description").value = "";
      row.querySelector('input[name="item_quantity[]"]').value = "1";
      row.querySelector(".item-price").value = "";
      bindRow(row);
      rows.appendChild(row);
      rows.dispatchEvent(new Event("input", { bubbles: true }));
    });
  }

  function initInvoicePaymentEntry() {
    var invoiceForm = document.getElementById("invoice-form");
    var dialog = document.getElementById("payment-entry-dialog");
    var entryForm = document.getElementById("payment-entry-form");
    var openButton = document.getElementById("open-payment-dialog");
    var cancelButton = document.getElementById("cancel-payment-dialog");
    var amountInput = document.getElementById("payment-entry-amount");
    var dateInput = document.getElementById("payment-entry-date");
    var methodInput = document.getElementById("payment-entry-method");
    var referenceInput = document.getElementById("payment-entry-reference");
    var errorElement = document.getElementById("payment-entry-error");
    var recordSection = document.querySelector(".invoice-payment-records");
    var recordList = document.getElementById("invoice-payment-record-list");
    var currencySelect = document.getElementById("invoice-currency");
    var currencyLabel = document.getElementById("payment-entry-currency");
    var dateError = document.getElementById("payment-date-error");
    var methodError = document.getElementById("payment-method-error");
    var referenceError = document.getElementById("payment-reference-error");
    var formError = document.getElementById("payment-form-error");
    var confirmPaymentButton = document.getElementById("confirm-payment-add");
    if (!invoiceForm || !dialog || !entryForm || !openButton || !cancelButton || !amountInput || !dateInput ||
        !methodInput || !referenceInput || !errorElement || !recordSection || !recordList) return;

    var currency = invoiceForm.dataset.currencyCode || "KES";
    var existingPaidCents = BigInt(invoiceForm.dataset.existingPaidCents || "0");
    var nextPaymentIndex = recordList.querySelectorAll("[data-pending-payment]").length;
    var addingPayment = false;
    var entryTouched = { amount: false, date: false, method: false, reference: false };
    var serverErrorPending = dialog.dataset.reopen === "true";
    var serverErrorField = dialog.dataset.serverErrorField || "";
    var serverErrorMessage = dialog.dataset.serverError || "";
    var summaryTotal = document.getElementById("invoice-summary-total");
    var summaryPaid = document.getElementById("invoice-summary-paid");
    var summaryBalance = document.querySelector(".ps-num");
    var summaryCurrency = document.querySelector(".ps-cur");
    var summarySection = document.querySelector(".pay-summary");
    var summaryPill = document.querySelector(".ps-pill");
    var summaryStatusText = document.querySelector(".ps-pill-text");
    var progressBar = document.getElementById("invoice-payment-progress");
    var progressFill = document.getElementById("invoice-payment-progress-fill");
    var metaPercent = document.getElementById("invoice-payment-meta-percent");
    var addPaymentButton = document.getElementById("open-payment-dialog");

    function decimalParts(value, maxWholeDigits) {
      var pattern = maxWholeDigits === 6 ? /^\d{1,6}(?:\.\d{1,2})?$/ : /^\d{1,10}(?:\.\d{1,2})?$/;
      var match = String(value || "").trim().match(pattern);
      if (!match) return null;
      var parts = match[0].split(".");
      return {
        whole: parts[0],
        fraction: (parts[1] || "").padEnd(2, "0")
      };
    }

    function toCents(value) {
      var parts = decimalParts(value, 10);
      if (!parts) return null;
      return BigInt(parts.whole) * 100n + BigInt(parts.fraction || "0");
    }

    function formatMoney(cents) {
      var negative = cents < 0n;
      var value = negative ? -cents : cents;
      var whole = (value / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      var fraction = (value % 100n).toString().padStart(2, "0").replace(/0+$/, "");
      return currency + " " + (negative ? "-" : "") + whole + (fraction ? "." + fraction : "");
    }

    function formatAmount(cents) {
      var value = cents < 0n ? -cents : cents;
      var whole = (value / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      var fraction = (value % 100n).toString().padStart(2, "0").replace(/0+$/, "");
      return whole + (fraction ? "." + fraction : "");
    }

    function invoiceTotalCents() {
      var subtotal = 0n;
      invoiceForm.querySelectorAll(".invoice-item-row").forEach(function (row) {
        var quantity = decimalParts(row.querySelector('[name="item_quantity[]"]').value, 6);
        var unit = toCents(row.querySelector('[name="item_price[]"]').value);
        if (!quantity || unit === null || unit <= 0n) return;
        var quantityHundredths = BigInt(quantity.whole) * 100n + BigInt(quantity.fraction || "0");
        if (quantityHundredths <= 0n) return;
        subtotal += (unit * quantityHundredths + 50n) / 100n;
      });
      var discount = decimalParts(invoiceForm.querySelector('[name="discount_percent"]').value, 3);
      var tax = decimalParts(invoiceForm.querySelector('[name="tax_percent"]').value, 3);
      if (!discount || !tax) return 0n;
      var discountBasisPoints = BigInt(discount.whole) * 100n + BigInt(discount.fraction || "0");
      var taxBasisPoints = BigInt(tax.whole) * 100n + BigInt(tax.fraction || "0");
      var discountCents = (subtotal / 10000n) * discountBasisPoints +
        ((subtotal % 10000n) * discountBasisPoints + 5000n) / 10000n;
      var taxableCents = subtotal - discountCents;
      var taxCents = (taxableCents / 10000n) * taxBasisPoints +
        ((taxableCents % 10000n) * taxBasisPoints + 5000n) / 10000n;
      return subtotal - discountCents + taxCents;
    }

    function updateSummary() {
      if (currencySelect && currencySelect.value) currency = currencySelect.value;
      if (currencyLabel) currencyLabel.textContent = currency;
      if (summaryCurrency) summaryCurrency.textContent = currency;
      var total = invoiceTotalCents();
      var payments = existingPaidCents;
      recordList.querySelectorAll('[data-pending-payment] input[name$="[amount]"]').forEach(function (input) {
        var amount = toCents(input.value);
        if (amount !== null && amount > 0n) payments += amount;
      });
      if (summaryTotal) summaryTotal.textContent = formatMoney(total);
      if (summaryPaid) summaryPaid.textContent = formatMoney(payments);
      recordList.querySelectorAll("[data-payment-amount-cents]").forEach(function (amount) {
        amount.textContent = formatMoney(BigInt(amount.dataset.paymentAmountCents || "0"));
      });
      var balance = total > payments ? total - payments : 0n;
      var percent = total > 0n
        ? Number((payments * 100n + total / 2n) / total > 100n ? 100n : (payments * 100n + total / 2n) / total)
        : 0;
      var issueNow = invoiceForm.querySelector('[name="issue_now"]');
      var dueDate = invoiceForm.querySelector('[name="due_date"]');
      var status = "unpaid";
      var statusLabel = "Unpaid";
      if (issueNow && !issueNow.checked) {
        status = "draft";
        statusLabel = "Draft";
      } else if (balance === 0n) {
        status = "paid";
        statusLabel = "Paid";
      } else if (dueDate && dueDate.value) {
        var todayValue = invoiceForm.dataset.currentDate;
        if (dueDate.value < todayValue) {
          status = "overdue";
          statusLabel = "Overdue";
        } else if (payments > 0n) {
          status = "partial";
          statusLabel = "Partially paid";
        }
      } else if (payments > 0n) {
        status = "partial";
        statusLabel = "Partially paid";
      }
      if (summaryBalance) summaryBalance.textContent = formatAmount(balance);
      if (summarySection) summarySection.dataset.state = total > 0n && balance === 0n ? "paid" : "due";
      if (summaryPill) summaryPill.dataset.status = status;
      if (summaryStatusText) summaryStatusText.textContent = statusLabel;
      if (progressBar) progressBar.setAttribute("aria-valuenow", String(percent));
      if (progressFill) {
        var percentageClasses = [];
        progressFill.classList.forEach(function (className) {
          if (className.indexOf("pct-tens-") === 0 || className.indexOf("pct-ones-") === 0 || className.indexOf("pct-decimals-") === 0) {
            percentageClasses.push(className);
          }
        });
        progressFill.classList.remove.apply(progressFill.classList, percentageClasses);
        progressFill.classList.add("pct-tens-" + Math.floor(percent / 10), "pct-ones-" + (percent % 10), "pct-decimals-0");
      }
      if (metaPercent) metaPercent.textContent = percent + "% paid";
      if (addPaymentButton) {
        var disabledReason = total <= 0n ? "Add invoice items first" : (balance === 0n ? "This invoice is fully paid" : "");
        addPaymentButton.disabled = disabledReason !== "";
        addPaymentButton.title = disabledReason || "Add payment record";
      }
      return { total: total, payments: payments, balance: balance };
    }

    function setAmountError(message) {
      errorElement.textContent = message;
      errorElement.hidden = message === "";
      amountInput.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function setFieldError(control, errorNode, message) {
      if (!control || !errorNode) return;
      errorNode.textContent = message;
      errorNode.hidden = message === "";
      control.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function setFormError(message) {
      if (!formError) return;
      formError.textContent = message;
      formError.hidden = message === "";
    }

    function validateEntry(showErrors) {
      var amountText = amountInput.value.trim();
      var amount = toCents(amountText);
      var totals = updateSummary();
      var balance = totals.balance;
      var amountMessage = "";
      if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(amountText) || amount === null || amount <= 0n) {
        amountMessage = "The amount must be a number above 0, with at most 2 decimals.";
      } else if (amount > balance) {
        amountMessage = "Amount is more than the balance of " + formatMoney(balance) + ".";
      }

      var dateMessage = !dateInput.value || dateInput.value > dateInput.max
        ? "Choose a valid payment date no later than today."
        : "";
      var methodMessage = !methodInput.value || !Array.prototype.some.call(methodInput.options, function (option) {
        return option.value === methodInput.value;
      }) ? "Choose a payment method." : "";
      var reference = referenceInput.value.trim();
      var referenceMessage = !reference
        ? "Enter a transaction code."
        : (reference.length > 150 ? "The transaction code must be 150 characters or fewer." : "");
      if (!referenceMessage && Array.prototype.some.call(recordList.querySelectorAll("[data-payment-reference]"), function (record) {
        return record.dataset.paymentReference.trim().toLocaleLowerCase() === reference.toLocaleLowerCase();
      })) {
        referenceMessage = "This transaction code is already used for this invoice.";
      }

      var formMessage = totals.total <= 0n
        ? "Add invoice items first."
        : (totals.payments > totals.total
          ? "Recorded payments exceed the invoice total. Increase the total before adding another payment."
          : "");
      if (formMessage) amountMessage = "";

      if (serverErrorPending && serverErrorMessage !== "") {
        if (serverErrorField === "reference") referenceMessage = serverErrorMessage;
        else if (serverErrorField === "date") dateMessage = serverErrorMessage;
        else if (serverErrorField === "method") methodMessage = serverErrorMessage;
        else if (serverErrorField === "amount") amountMessage = serverErrorMessage;
        else formMessage = serverErrorMessage;
      }

      var showAmount = showErrors || entryTouched.amount || (serverErrorPending && serverErrorField === "amount");
      var showDate = showErrors || entryTouched.date || (serverErrorPending && serverErrorField === "date");
      var showMethod = showErrors || entryTouched.method || (serverErrorPending && serverErrorField === "method");
      var showReference = showErrors || entryTouched.reference || (serverErrorPending && serverErrorField === "reference");
      setAmountError(showAmount ? amountMessage : "");
      setFieldError(dateInput, dateError, showDate ? dateMessage : "");
      setFieldError(methodInput, methodError, showMethod ? methodMessage : "");
      setFieldError(referenceInput, referenceError, showReference ? referenceMessage : "");
      setFormError(showErrors || serverErrorPending || formMessage ? formMessage : "");

      var valid = !amountMessage && !dateMessage && !methodMessage && !referenceMessage && !formMessage;
      if (confirmPaymentButton) confirmPaymentButton.disabled = !valid || addingPayment;
      return {
        valid: valid,
        firstInvalid: formMessage || amountMessage ? amountInput
          : dateMessage ? dateInput
            : methodMessage ? methodInput
              : referenceMessage ? referenceInput
                : null,
        amount: amountText,
        date: dateInput.value,
        method: methodInput.value,
        reference: reference
      };
    }

    function hiddenPaymentField(index, name, value) {
      var input = document.createElement("input");
      input.type = "hidden";
      input.name = "pending_payments[" + index + "][" + name + "]";
      input.value = value;
      return input;
    }

    function addPendingPayment(amount, date, method, reference) {
      var index = nextPaymentIndex++;
      var item = document.createElement("li");
      item.className = "invoice-payment-record";
      item.dataset.pendingPayment = String(index);
      item.dataset.paymentReference = reference;
      item.appendChild(hiddenPaymentField(index, "amount", amount));
      item.appendChild(hiddenPaymentField(index, "payment_date", date));
      item.appendChild(hiddenPaymentField(index, "method", method));
      item.appendChild(hiddenPaymentField(index, "reference", reference));

      var detail = document.createElement("span");
      detail.textContent = date + " · " + (method || "—") + " · " + (reference || "—");
      var value = document.createElement("strong");
      var amountCents = toCents(amount);
      value.dataset.paymentAmountCents = amountCents.toString();
      value.textContent = formatMoney(amountCents);
      var state = document.createElement("span");
      state.className = "invoice-payment-record__state";
      state.textContent = "Not saved yet";
      var remove = document.createElement("button");
      remove.className = "link link--danger invoice-payment-remove";
      remove.type = "button";
      remove.dataset.removePayment = "";
      remove.textContent = "Remove";
      item.append(detail, value, state, remove);
      recordList.appendChild(item);
      recordSection.hidden = false;
    }

    function clearEntry() {
      entryForm.reset();
      dateInput.value = dateInput.defaultValue;
      setAmountError("");
      setFieldError(dateInput, dateError, "");
      setFieldError(methodInput, methodError, "");
      setFieldError(referenceInput, referenceError, "");
      setFormError("");
      Object.keys(entryTouched).forEach(function (field) { entryTouched[field] = false; });
      addingPayment = false;
      if (confirmPaymentButton) confirmPaymentButton.disabled = true;
    }

    function keepFocusInDialog(event) {
      if (!dialog.open || event.key !== "Tab") return;
      var focusable = Array.prototype.slice.call(dialog.querySelectorAll(
        'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
      )).filter(function (element) {
        return !element.hidden && element.getAttribute("aria-hidden") !== "true";
      });
      if (!focusable.length) {
        event.preventDefault();
        dialog.focus();
        return;
      }
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      var active = document.activeElement;
      if (event.shiftKey && (active === first || active === dialog || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    }

    function openDialog() {
      updateSummary();
      if (typeof dialog.showModal === "function") {
        addingPayment = false;
        validateEntry(false);
        dialog.showModal();
        window.requestAnimationFrame(function () { amountInput.focus(); });
      }
    }

    invoiceForm.addEventListener("input", function () {
      updateSummary();
      validateEntry(false);
    });
    invoiceForm.addEventListener("change", function () {
      updateSummary();
      validateEntry(false);
    });
    recordList.addEventListener("click", function (event) {
      var removeRecorded = event.target.closest("[data-remove-recorded-payment]");
      if (removeRecorded) {
        var recordedItem = removeRecorded.closest("[data-recorded-payment]");
        if (!recordedItem) return;
        var paymentId = recordedItem.dataset.recordedPayment;
        var removalInput = document.createElement("input");
        removalInput.type = "hidden";
        removalInput.name = "removed_payment_ids[]";
        removalInput.value = paymentId;
        invoiceForm.appendChild(removalInput);
        existingPaidCents -= BigInt(recordedItem.dataset.amountCents || "0");
        recordedItem.remove();
        if (!recordList.querySelector("li")) recordSection.hidden = true;
        updateSummary();
        validateEntry(false);
        return;
      }
      var remove = event.target.closest("[data-remove-payment]");
      if (!remove) return;
      var item = remove.closest("[data-pending-payment]");
      if (item) item.remove();
      if (!recordList.querySelector("li")) recordSection.hidden = true;
      updateSummary();
      validateEntry(false);
    });
    openButton.addEventListener("click", openDialog);
    cancelButton.addEventListener("click", function () { dialog.close(); });
    document.addEventListener("keydown", keepFocusInDialog);
    dialog.addEventListener("close", function () {
      clearEntry();
      openButton.focus();
    });
    [amountInput, dateInput, methodInput, referenceInput].forEach(function (control, index) {
      var field = ["amount", "date", "method", "reference"][index];
      ["input", "change", "blur"].forEach(function (eventName) {
        control.addEventListener(eventName, function () {
          entryTouched[field] = true;
          if (serverErrorPending && (serverErrorField === field || serverErrorField === "form")) {
            serverErrorPending = false;
          }
          validateEntry(false);
        });
      });
    });
    entryForm.addEventListener("submit", function (event) {
      event.preventDefault();
      if (addingPayment) return;
      var validation = validateEntry(true);
      if (!validation.valid) {
        if (validation.firstInvalid) validation.firstInvalid.focus();
        return;
      }
      addingPayment = true;
      if (confirmPaymentButton) confirmPaymentButton.disabled = true;
      addPendingPayment(validation.amount, validation.date, validation.method, validation.reference);
      updateSummary();
      dialog.close();
    });

    updateSummary();
    if (dialog.dataset.reopen === "true") {
      var serverError = dialog.dataset.serverError || "Check the payment details and try again.";
      if (serverErrorField === "reference") {
        entryTouched.reference = true;
        setFieldError(referenceInput, referenceError, serverError);
      } else if (serverErrorField === "date") {
        entryTouched.date = true;
        setFieldError(dateInput, dateError, serverError);
      } else if (serverErrorField === "method") {
        entryTouched.method = true;
        setFieldError(methodInput, methodError, serverError);
      } else if (serverErrorField === "form") {
        setFormError(serverError);
      } else {
        entryTouched.amount = true;
        setAmountError(serverError);
      }
      validateEntry(false);
      openDialog();
      if (serverErrorField !== "form") {
        var target = serverErrorField === "reference" ? referenceInput
          : serverErrorField === "date" ? dateInput
            : serverErrorField === "method" ? methodInput : amountInput;
        window.requestAnimationFrame(function () { target.focus(); });
      }
    }
    validateEntry(false);
  }

  function initInvoiceViewPaymentDialog() {
    var dialog = document.getElementById("invoice-payment-dialog");
    var openButton = document.getElementById("open-invoice-payment-dialog");
    var cancelButton = document.getElementById("cancel-invoice-payment");
    if (!dialog || !openButton || !cancelButton) return;
    var form = dialog.querySelector("form");
    var amountInput = form && form.querySelector('[name="amount"]');
    var dateInput = form && form.querySelector('[name="payment_date"]');
    var methodInput = form && form.querySelector('[name="method"]');
    var referenceInput = form && form.querySelector('[name="reference"]');
    var amountError = document.getElementById("invoice-payment-amount-error");
    var dateError = document.getElementById("invoice-payment-date-error");
    var methodError = document.getElementById("invoice-payment-method-error");
    var referenceError = document.getElementById("invoice-payment-reference-error");
    var submitButton = form && form.querySelector('button[type="submit"]');
    var balanceLabel = dialog.dataset.balanceLabel || "";
    var existingReferences = [];
    try {
      existingReferences = JSON.parse(dialog.dataset.existingReferences || "[]");
    } catch (error) {
      console.error("Could not read existing invoice payment references.", error);
    }

    function setPaymentFieldError(control, errorNode, message, showError) {
      if (!control || !errorNode) return message === "";
      errorNode.textContent = showError ? message : "";
      errorNode.hidden = !showError || message === "";
      if (message && showError) control.setAttribute("aria-invalid", "true");
      else control.removeAttribute("aria-invalid");
      return message === "";
    }

    function validatePayment(showErrors) {
      if (!form || !amountInput || !dateInput || !methodInput || !referenceInput || !submitButton) return false;
      var amountText = amountInput.value.trim();
      var amountMessage = "";
      if (!amountText) amountMessage = "Enter a payment amount.";
      else if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(amountText) || Number(amountText) <= 0) {
        amountMessage = "Enter a valid payment amount above zero.";
      } else if (amountInput.max && Number(amountText) > Number(amountInput.max)) {
        amountMessage = "Payment cannot exceed the outstanding invoice balance" +
          (balanceLabel ? " of " + balanceLabel : "") + ".";
      }

      var dateMessage = !dateInput.value || dateInput.value > dateInput.max
        ? "Choose a valid payment date no later than today."
        : "";
      var methodMessage = !methodInput.value || !Array.prototype.some.call(methodInput.options, function (option) {
        return option.value === methodInput.value;
      }) ? "Choose a payment method." : "";
      var reference = referenceInput.value.trim();
      var referenceMessage = !reference
        ? "Enter a transaction reference."
        : (reference.length > 150 ? "The transaction reference must be 150 characters or fewer." : "");
      if (!referenceMessage && existingReferences.some(function (existingReference) {
        return String(existingReference).trim().toLocaleLowerCase() === reference.toLocaleLowerCase();
      })) {
        referenceMessage = "This transaction reference is already used for this invoice.";
      }

      var valid = true;
      valid = setPaymentFieldError(amountInput, amountError, amountMessage,
        showErrors || amountInput.dataset.validationTouched === "true") && valid;
      valid = setPaymentFieldError(dateInput, dateError, dateMessage,
        showErrors || dateInput.dataset.validationTouched === "true") && valid;
      valid = setPaymentFieldError(methodInput, methodError, methodMessage,
        showErrors || methodInput.dataset.validationTouched === "true") && valid;
      valid = setPaymentFieldError(referenceInput, referenceError, referenceMessage,
        showErrors || referenceInput.dataset.validationTouched === "true") && valid;
      submitButton.disabled = !valid;
      return valid;
    }

    openButton.addEventListener("click", function () {
      if (typeof dialog.showModal === "function") {
        validatePayment(false);
        dialog.showModal();
      }
    });
    cancelButton.addEventListener("click", function () { dialog.close(); });
    dialog.addEventListener("close", function () { openButton.focus(); });
    [amountInput, dateInput, methodInput, referenceInput].forEach(function (control) {
      if (!control) return;
      ["input", "change"].forEach(function (eventName) {
        control.addEventListener(eventName, function () {
          control.dataset.validationTouched = "true";
          validatePayment(false);
        });
      });
      control.addEventListener("blur", function () {
        control.dataset.validationTouched = "true";
        validatePayment(false);
      });
    });
    if (form && submitButton) {
      form.addEventListener("submit", function (event) {
        if (!validatePayment(true)) {
          event.preventDefault();
          var firstInvalid = form.querySelector('[aria-invalid="true"]');
          if (firstInvalid) firstInvalid.focus();
        }
      });
    }
    validatePayment(false);
    if (dialog.dataset.open === "true" && typeof dialog.showModal === "function") {
      dialog.showModal();
      validatePayment(true);
      var firstInvalid = form && form.querySelector('[aria-invalid="true"]');
      var firstInput = dialog.querySelector("input:not([type='hidden']), select");
      (firstInvalid || firstInput || openButton).focus();
    }
  }

  function inputLabel(control) {
    var field = control.closest(".field");
    var label = field && field.querySelector(".field__label");
    if (label) return label.textContent.replace(/\s*\*\s*$/, "").trim().replace(/:$/, "");
    return (control.name || "This field").replace(/\[\]$/, "").replace(/[_-]+/g, " ").replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  }

  function validationMessage(control) {
    var value = control.value.trim();
    var label = inputLabel(control);
    var newPassword = control.form && control.form.querySelector('[name="new_password"]');
    var required = control.dataset.validationRequired === "true" || control.required ||
      control.name === "current_password" && newPassword && newPassword.value.trim() !== "";

    if (required && (control.type === "checkbox" ? !control.checked : value === "")) {
      return label + " is required.";
    }
    if (value === "") return "";
    if (control.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      return "Enter a valid email address.";
    }
    if (control.pattern) {
      try {
        if (!new RegExp("^(?:" + control.pattern + ")$").test(value)) {
          return control.dataset.validationPatternMessage || "Enter a value in the requested format.";
        }
      } catch (error) {
        return "This field has an invalid validation pattern.";
      }
    }
    if (control.type === "number") {
      var number = Number(value);
      if (!Number.isFinite(number)) return "Enter a valid number.";
      if (control.min !== "" && number < Number(control.min)) return "Enter a value of at least " + control.min + ".";
      if (control.max !== "" && number > Number(control.max)) return "Enter a value no greater than " + control.max + ".";
      if (control.step && control.step !== "any") {
        var step = Number(control.step);
        var base = control.min !== "" ? Number(control.min) : 0;
        if (step > 0 && Math.abs((number - base) / step - Math.round((number - base) / step)) > 1e-7) {
          return "Use increments of " + control.step + ".";
        }
      }
    }
    if (control.type === "date") {
      if (control.min && value < control.min) {
        return "Choose a date on or after " + control.min + ".";
      }
      if (control.max && value > control.max) return "Choose a date on or before " + control.max + ".";
      var today = new Date();
      var localToday = today.getFullYear() + "-" + String(today.getMonth() + 1).padStart(2, "0") + "-" + String(today.getDate()).padStart(2, "0");
      if (control.name === "issue_date" && value > localToday) {
        return "Issue date cannot be in the future.";
      }
    }
    var minLength = control.minLength > 0 ? control.minLength : Number(control.dataset.validationMinlength || 0);
    if (minLength > 0 && value.length < minLength) {
      return "Enter at least " + minLength + " characters.";
    }
    if (control.maxLength > 0 && value.length > control.maxLength) {
      return "Enter no more than " + control.maxLength + " characters.";
    }
    return "";
  }

  function findErrorElement(control) {
    var field = control.closest(".field");
    var existing = field && field.querySelector(".field__error");
    if (!existing) {
      var describedBy = control.getAttribute("aria-describedby");
      if (describedBy) {
        var ids = describedBy.split(/\s+/);
        for (var i = 0; i < ids.length; i += 1) {
          var described = document.getElementById(ids[i]);
          if (described && described.classList.contains("field__error")) {
            existing = described;
            break;
          }
        }
      }
    }
    return existing;
  }

  function errorElement(control) {
    var existing = findErrorElement(control);
    if (!existing) {
      var field = control.closest(".field");
      existing = document.createElement("span");
      existing.className = "field__error";
      existing.setAttribute("aria-live", "polite");
      existing.id = "field-error-" + (++validationErrorId);
      if (field) {
        field.appendChild(existing);
      } else {
        control.insertAdjacentElement("afterend", existing);
      }
    }
    return existing;
  }

  function setFieldError(control, message) {
    var error = message ? errorElement(control) : findErrorElement(control);
    if (!error) return;
    if (message) {
      error.textContent = message;
      error.hidden = false;
      control.setAttribute("aria-invalid", "true");
      var describedBy = (control.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
      if (describedBy.indexOf(error.id) === -1) describedBy.push(error.id);
      control.setAttribute("aria-describedby", describedBy.join(" "));
    } else {
      error.textContent = "";
      error.hidden = true;
      control.removeAttribute("aria-invalid");
      var existingIds = (control.getAttribute("aria-describedby") || "").split(/\s+/).filter(function (id) {
        return id && id !== error.id;
      });
      if (existingIds.length) control.setAttribute("aria-describedby", existingIds.join(" "));
      else control.removeAttribute("aria-describedby");
    }
  }

  function initFormValidation() {
    document.querySelectorAll("form").forEach(function (form) {
      if (form.hasAttribute("data-validation-managed")) return;
      function shouldValidate(control) {
        if (control.disabled || control.closest("[hidden]") || ["hidden", "submit", "button", "reset"].includes(control.type)) return false;
        if (control.matches("[data-domain-company], [data-domain-phone], [data-domain-name], [data-domain-expiry], [data-domain-email], [data-domain-billing], [data-primary-domain], [data-primary-domain-expiry], [data-workspace-domain], [data-workspace-expiry]")) return false;
        var newPassword = form.querySelector('[name="new_password"]');
        return control.dataset.validationRequired === "true" || control.required ||
          control.name === "current_password" && newPassword && newPassword.value.trim() !== "" ||
          control.type === "email" && control.value.trim() !== "" ||
          Boolean(control.pattern) && control.value.trim() !== "" ||
          control.maxLength > 0 && control.value.length > control.maxLength ||
          (control.minLength > 0 || Number(control.dataset.validationMinlength || 0) > 0) && control.value !== "" ||
          (control.type === "number" || control.type === "date") && control.value !== "" && (control.min !== "" || control.max !== "" || control.name === "issue_date" || control.name === "due_date");
      }
      var hasValidation = Array.prototype.some.call(form.querySelectorAll("input, select, textarea"), function (control) {
        return shouldValidate(control);
      });
      if (!hasValidation) return;

      form.noValidate = true;
      form.dataset.customValidation = "true";
      function validateControl(control) {
        var message = validationMessage(control);
        setFieldError(control, message);
        return message === "";
      }
      form.addEventListener("submit", function (event) {
        var firstInvalid = null;
        var valid = true;
        form.querySelectorAll("input, select, textarea").forEach(function (control) {
          if (!shouldValidate(control)) return;
          var error = validationMessage(control);
          setFieldError(control, error);
          if (error) {
            valid = false;
            if (!firstInvalid) firstInvalid = control;
          }
        });
        if (!valid) {
          event.preventDefault();
          if (firstInvalid) firstInvalid.focus();
          return;
        }
        if (form.hasAttribute("data-disable-on-submit")) {
          if (form.hasAttribute("data-confirm") && form.dataset.confirmApproved !== "true") return;
          delete form.dataset.confirmApproved;
          disableFormSubmitButton(form);
        }
      });
      form.addEventListener("input", function (event) {
        var control = event.target;
        if (control.matches("input, select, textarea") && shouldValidate(control) && (control.dataset.validationTouched === "true" || control.getAttribute("aria-invalid") === "true")) {
          validateControl(control);
        }
      });
      form.addEventListener("change", function (event) {
        var control = event.target;
        if (control.matches("input, select, textarea") && shouldValidate(control) && (control.dataset.validationTouched === "true" || control.getAttribute("aria-invalid") === "true")) {
          validateControl(control);
        }
      });
      form.addEventListener("focusout", function (event) {
        var control = event.target;
        if (control.matches("input, select, textarea") && shouldValidate(control)) {
          control.dataset.validationTouched = "true";
          validateControl(control);
        }
      });
    });
  }

  function initPaymentCurrencyLabel() {
    var invoiceSelect = document.getElementById("payment-invoice");
    var currencyLabel = document.getElementById("payment-invoice-currency");
    if (!invoiceSelect || !currencyLabel) return;
    function updateCurrency() {
      var option = invoiceSelect.selectedOptions[0];
      currencyLabel.textContent = option && option.dataset.currency
        ? option.dataset.currency
        : currencyLabel.dataset.defaultCurrency || "KES";
    }
    currencyLabel.dataset.defaultCurrency = currencyLabel.textContent;
    invoiceSelect.addEventListener("change", updateCurrency);
    updateCurrency();
  }

  function initPaymentHistoryTable() {
    var controls = document.querySelector("[data-payment-history-controls]");
    var table = document.querySelector("[data-payment-history-table]");
    var footer = document.querySelector("[data-payment-history-footer]");
    var emptyState = document.querySelector("[data-payment-empty]");
    var tableScroll = table && table.closest(".dt-scroll");
    if (!controls || !table || !footer || !emptyState || !tableScroll) return;

    var search = controls.querySelector("[data-payment-search]");
    var methodFilter = controls.querySelector("[data-payment-method-filter]");
    var currencyFilter = controls.querySelector("[data-payment-currency-filter]");
    var sortBy = controls.querySelector("[data-payment-sortby]");
    var body = table.tBodies[0];
    var count = footer.querySelector("[data-payment-history-count]");
    var pagination = footer.querySelector("[data-payment-pagination]");
    var pageSizeSelect = footer.querySelector("[data-payment-page-size]");
    if (!search || !methodFilter || !currencyFilter || !sortBy || !pageSizeSelect || !body || !count || !pagination) return;

    var rows = Array.prototype.slice.call(body.querySelectorAll("[data-payment-row]"));
    var page = 1;
    var pageSize = Number(pageSizeSelect.value) || 10;
    var sortKey = "date";
    var sortDirection = -1;
    var collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
    var keys = {
      date: "paymentDate",
      customer: "paymentCustomer",
      invoice: "paymentInvoice",
      method: "paymentMethod",
      reference: "paymentReference",
      amount: "paymentAmount"
    };

    function filteredRows() {
      var query = search.value.trim().toLocaleLowerCase();
      var method = methodFilter.value;
      var currency = currencyFilter.value;
      return rows.filter(function (row) {
        return (!query || row.textContent.toLocaleLowerCase().indexOf(query) !== -1) &&
          (!method || row.dataset.paymentMethod === method) &&
          (!currency || row.dataset.paymentCurrency === currency);
      }).sort(function (left, right) {
        var leftValue = left.dataset[keys[sortKey]] || "";
        var rightValue = right.dataset[keys[sortKey]] || "";
        var comparison = sortKey === "amount"
          ? Number(leftValue) - Number(rightValue)
          : collator.compare(leftValue, rightValue);
        return comparison * sortDirection;
      });
    }

    function addPageButton(label, targetPage, options, iconDirection) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "dt-page";
      button.disabled = Boolean(options.disabled);
      if (options.current) {
        button.classList.add("dt-page-num");
        button.setAttribute("aria-current", "page");
        button.setAttribute("aria-label", "Page " + label + ", current page");
      } else if (/^\d+$/.test(label)) {
        button.classList.add("dt-page-num");
        button.setAttribute("aria-label", "Go to page " + label);
      } else if (!options.disabled) {
        button.setAttribute("aria-label", label);
      }
      if (iconDirection) {
        var icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        icon.setAttribute("width", "16");
        icon.setAttribute("height", "16");
        icon.setAttribute("viewBox", "0 0 24 24");
        icon.setAttribute("fill", "none");
        icon.setAttribute("stroke", "currentColor");
        icon.setAttribute("stroke-width", "2");
        icon.setAttribute("stroke-linecap", "round");
        icon.setAttribute("stroke-linejoin", "round");
        icon.setAttribute("aria-hidden", "true");
        var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("d", iconDirection === "previous" ? "M15 6l-6 6 6 6" : "M9 6l6 6-6 6");
        icon.appendChild(path);
        if (iconDirection === "previous") button.appendChild(icon);
        else button.appendChild(document.createTextNode(label));
        if (iconDirection === "previous") button.appendChild(document.createTextNode(label));
        else button.appendChild(icon);
      } else {
        button.textContent = label;
      }
      button.addEventListener("click", function () {
        page = targetPage;
        render();
      });
      pagination.appendChild(button);
    }

    function render() {
      var matchingRows = filteredRows();
      var pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
      page = Math.min(page, pageCount);
      var hasResults = matchingRows.length > 0;
      tableScroll.hidden = !hasResults;
      footer.hidden = !hasResults;
      emptyState.hidden = hasResults;
      if (!rows.length) {
        emptyState.textContent = "No payments have been recorded.";
      } else if (!hasResults && !emptyState.querySelector("[data-payment-clear]")) {
        emptyState.innerHTML = 'No payments match your search or filters. <a href="#" data-payment-clear>Clear filters</a>';
      }
      if (hasResults) {
        emptyState.innerHTML = 'No payments match your search or filters. <a href="#" data-payment-clear>Clear filters</a>';
      }
      var first = (page - 1) * pageSize;
      var visibleRows = matchingRows.slice(first, first + pageSize);
      var visibleSet = new Set(visibleRows);

      matchingRows.forEach(function (row) { body.appendChild(row); });
      rows.forEach(function (row) {
        if (!visibleSet.has(row) && matchingRows.indexOf(row) === -1) body.appendChild(row);
      });
      rows.forEach(function (row) { row.hidden = !visibleSet.has(row); });
      count.innerHTML = "Showing <b>" + (first + 1) + "–" + (first + visibleRows.length) + "</b> of <b>" + matchingRows.length + "</b> payments";
      pagination.replaceChildren();
      var pageInfo = document.createElement("span");
      pageInfo.className = "dt-pageinfo";
      pageInfo.textContent = "Page " + page + " of " + pageCount;
      addPageButton("Previous", Math.max(1, page - 1), { disabled: page === 1 }, "previous");
      if (pageCount <= 7) {
        for (var number = 1; number <= pageCount; number += 1) {
          addPageButton(String(number), number, { current: number === page });
        }
      } else {
        var pageNumbers = new Set([1, pageCount, page - 1, page, page + 1]);
        if (page <= 3) [2, 3, 4].forEach(function (number) { pageNumbers.add(number); });
        if (page >= pageCount - 2) [pageCount - 3, pageCount - 2, pageCount - 1].forEach(function (number) { pageNumbers.add(number); });
        var orderedPages = Array.from(pageNumbers).filter(function (number) {
          return number >= 1 && number <= pageCount;
        }).sort(function (left, right) { return left - right; });
        var previousNumber = 0;
        orderedPages.forEach(function (number) {
          if (previousNumber && number - previousNumber > 1) {
            var ellipsis = document.createElement("span");
            ellipsis.className = "dt-page dt-ellipsis";
            ellipsis.setAttribute("aria-hidden", "true");
            ellipsis.textContent = "…";
            pagination.appendChild(ellipsis);
          }
          addPageButton(String(number), number, { current: number === page });
          previousNumber = number;
        });
      }
      pagination.appendChild(pageInfo);
      addPageButton("Next", Math.min(pageCount, page + 1), { disabled: page === pageCount }, "next");
      table.querySelectorAll("[data-sort-column]").forEach(function (header) {
        var active = header.dataset.sortColumn === sortKey;
        if (active) header.setAttribute("aria-sort", sortDirection === 1 ? "ascending" : "descending");
        else header.removeAttribute("aria-sort");
        var button = header.querySelector(".dt-sort");
        if (!button) return;
        button.querySelectorAll(".dt-sort-arrow, .dt-arrow-idle").forEach(function (indicator) { indicator.remove(); });
        var icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        icon.setAttribute("width", "14");
        icon.setAttribute("height", "14");
        icon.setAttribute("viewBox", "0 0 24 24");
        icon.setAttribute("fill", "none");
        icon.setAttribute("stroke", "currentColor");
        icon.setAttribute("stroke-width", active ? "2.4" : "2");
        icon.setAttribute("stroke-linecap", "round");
        icon.setAttribute("stroke-linejoin", "round");
        icon.setAttribute("aria-hidden", "true");
        var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("d", active
          ? (sortDirection === -1 ? "M12 5v14M6 13l6 6 6-6" : "M12 19V5M6 11l6-6 6 6")
          : "M8 9l4-4 4 4M8 15l4 4 4-4");
        icon.appendChild(path);
        icon.classList.add(active ? "dt-sort-arrow" : "dt-arrow-idle");
        button.appendChild(icon);
      });
      sortBy.value = sortKey + ":" + sortDirection;
    }

    [search, methodFilter, currencyFilter].forEach(function (control) {
      control.addEventListener("input", function () { page = 1; render(); });
      control.addEventListener("change", function () { page = 1; render(); });
    });
    pageSizeSelect.addEventListener("change", function () {
      pageSize = Math.max(1, Number(pageSizeSelect.value) || 10);
      page = 1;
      render();
    });
    table.querySelectorAll("[data-payment-sort]").forEach(function (button) {
      button.addEventListener("click", function () {
        var selectedKey = button.dataset.paymentSort;
        sortDirection = selectedKey === sortKey ? -sortDirection : (selectedKey === "date" ? -1 : 1);
        sortKey = selectedKey;
        render();
      });
    });
    sortBy.addEventListener("change", function () {
      var selected = sortBy.value.split(":");
      if (!keys[selected[0]] || !["1", "-1"].includes(selected[1])) return;
      sortKey = selected[0];
      sortDirection = Number(selected[1]);
      page = 1;
      render();
    });
    emptyState.addEventListener("click", function (event) {
      var link = event.target.closest("[data-payment-clear]");
      if (!link) return;
      event.preventDefault();
      search.value = "";
      methodFilter.value = "";
      currencyFilter.value = "";
      page = 1;
      render();
      search.focus();
    });
    render();
  }

  function initEnhancedTables() {
    var tables = document.querySelectorAll("[data-enhanced-table]");
    var collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });

    tables.forEach(function (table) {
      var body = table.tBodies[0];
      var wrap = table.closest(".table-wrap");
      if (!body || !wrap || table.dataset.tableReady === "true") return;
      table.dataset.tableReady = "true";

      var rows = Array.prototype.slice.call(body.rows);
      var tableId = table.id;
      var existingSearch = tableId
        ? document.querySelector('[data-table-search-for="' + tableId + '"]')
        : null;
      var search = existingSearch;
      var section = table.closest(".card") || wrap.parentElement;
      var toolbar = document.createElement("div");
      toolbar.className = "payment-history-controls enhanced-table-controls";
      toolbar.dataset.controlsFor = tableId;
      var pageSizeOnly = table.dataset.controls === "page-size-only";

      if (!search && !pageSizeOnly) {
        var searchLabel = document.createElement("label");
        searchLabel.className = "field payment-history-search";
        var searchCaption = document.createElement("span");
        searchCaption.className = "field__label";
        searchCaption.textContent = "Search table";
        search = document.createElement("input");
        search.className = "input";
        search.type = "search";
        search.placeholder = "Search records";
        search.setAttribute("aria-label", "Search table records");
        searchLabel.append(searchCaption, search);
        toolbar.appendChild(searchLabel);
      }

      var filterColumn = Number(table.dataset.filterColumn);
      var filters = [];
      var existingFilter = tableId
        ? document.querySelector('[data-table-filter-for="' + tableId + '"]')
        : null;
      if (existingFilter && !pageSizeOnly) {
        filters.push({ select: existingFilter, key: table.dataset.filterKey || "", column: -1 });
      } else if (!pageSizeOnly) {
        var filterColumns = (table.dataset.filterColumns || "")
          .split(",")
          .map(function (value) { return Number(value.trim()); })
          .filter(function (value) { return Number.isInteger(value) && value >= 0; });
        if (!filterColumns.length && Number.isInteger(filterColumn) && filterColumn >= 0) {
          filterColumns.push(filterColumn);
        }
        var filterLabels = (table.dataset.filterLabels || "").split(",");
        filterColumns.forEach(function (column, filterIndex) {
          var filterLabel = document.createElement("label");
          filterLabel.className = "field";
          var filterCaption = document.createElement("span");
          filterCaption.className = "field__label";
          filterCaption.textContent = filterLabels[filterIndex] || (filterColumns.length === 1
            ? table.dataset.filterLabel || "Filter"
            : (table.tHead.rows[0].cells[column] ? table.tHead.rows[0].cells[column].textContent.trim() : "Filter"));
          var filterSelect = document.createElement("select");
          filterSelect.className = "input";
          filterSelect.setAttribute("aria-label", filterCaption.textContent);
          filterLabel.append(filterCaption, filterSelect);
          var configuredValues = table.getAttribute("data-filter-options-" + column);
          var values = configuredValues === null
            ? Array.from(new Set(rows.map(function (row) {
              return (row.cells[column] ? row.cells[column].textContent : "").trim();
            }).filter(Boolean))).sort(collator.compare)
            : configuredValues.split(",").map(function (value) { return value.trim(); }).filter(Boolean);
          var allOption = document.createElement("option");
          allOption.value = "";
          allOption.textContent = "All";
          filterSelect.appendChild(allOption);
          values.forEach(function (value) {
            var option = document.createElement("option");
            option.value = value;
            option.textContent = value;
            filterSelect.appendChild(option);
          });
          toolbar.appendChild(filterLabel);
          filters.push({ select: filterSelect, key: "", column: column });
        });
      }

      var statusFilterColumn = Number(table.dataset.statusFilterColumn);
      if (Number.isInteger(statusFilterColumn) && statusFilterColumn >= 0) {
        var statusFilterLabel = document.createElement("label");
        statusFilterLabel.className = "field";
        var statusFilterCaption = document.createElement("span");
        statusFilterCaption.className = "field__label";
        statusFilterCaption.textContent = "Invoice status";
        var statusFilterSelect = document.createElement("select");
        statusFilterSelect.className = "input";
        statusFilterSelect.setAttribute("aria-label", statusFilterCaption.textContent);
        statusFilterLabel.append(statusFilterCaption, statusFilterSelect);
        var statuses = Array.from(new Set(rows.map(function (row) {
          return (row.cells[statusFilterColumn] ? row.cells[statusFilterColumn].textContent : "").trim();
        }).filter(Boolean))).sort(collator.compare);
        var allStatusesOption = document.createElement("option");
        allStatusesOption.value = "";
        allStatusesOption.textContent = "All statuses";
        statusFilterSelect.appendChild(allStatusesOption);
        statuses.forEach(function (status) {
          var option = document.createElement("option");
          option.value = status;
          option.textContent = status;
          statusFilterSelect.appendChild(option);
        });
        toolbar.appendChild(statusFilterLabel);
        filters.push({ select: statusFilterSelect, key: "", column: statusFilterColumn });
      }

      var monthColumn = Number(table.dataset.filterMonthColumn);
      if (!pageSizeOnly && Number.isInteger(monthColumn) && monthColumn >= 0) {
        var monthLabel = document.createElement("label");
        monthLabel.className = "field enhanced-table-month-filter";
        var monthCaption = document.createElement("span");
        monthCaption.className = "field__label";
        monthCaption.textContent = "Filter by calendar month";
        var monthSelect = document.createElement("select");
        monthSelect.className = "input";
        monthSelect.setAttribute("aria-label", monthCaption.textContent);
        monthLabel.append(monthCaption, monthSelect);
        var months = new Map();
        rows.forEach(function (row) {
          var cell = row.cells[monthColumn];
          var dateValue = cell ? (cell.dataset.sortValue || cell.textContent).trim() : "";
          var match = dateValue.match(/^(\d{4})-(\d{2})/);
          if (!match) return;
          var key = match[1] + "-" + match[2];
          var monthDate = new Date(Number(match[1]), Number(match[2]) - 1, 1);
          months.set(key, monthDate.toLocaleDateString(undefined, { month: "long", year: "numeric" }));
          row.dataset.calendarMonth = key;
        });
        var allMonthsOption = document.createElement("option");
        allMonthsOption.value = "";
        allMonthsOption.textContent = "All months";
        monthSelect.appendChild(allMonthsOption);
        Array.from(months.keys()).sort().reverse().forEach(function (monthKey) {
          var option = document.createElement("option");
          option.value = monthKey;
          option.textContent = months.get(monthKey);
          monthSelect.appendChild(option);
        });
        toolbar.appendChild(monthLabel);
        filters.push({ select: monthSelect, key: "calendarMonth", column: -1 });
      }

      var sizeLabel = document.createElement("label");
      sizeLabel.className = "dt-rpp";
      sizeLabel.appendChild(document.createTextNode("Rows per page"));
      var sizeField = document.createElement("span");
      sizeField.className = "dt-field";
      var pageSizeSelect = document.createElement("select");
      pageSizeSelect.className = "dt-select";
      pageSizeSelect.setAttribute("aria-label", "Rows per page");
      [10, 25, 50, 100, 250].forEach(function (size) {
        var option = document.createElement("option");
        option.value = String(size);
        option.textContent = String(size);
        if (size === 10) option.selected = true;
        pageSizeSelect.appendChild(option);
      });
      var sizeIcon = document.createElement("span");
      sizeIcon.className = "dt-icon-right";
      sizeIcon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
      sizeField.append(pageSizeSelect, sizeIcon);
      sizeLabel.appendChild(sizeField);
      if (toolbar.childElementCount > 0) {
        section.insertBefore(toolbar, wrap);
      }
      if (existingSearch) {
        existingSearch.addEventListener("input", function () { page = 1; render(); });
      }

      var footer = document.createElement("div");
      footer.className = "dt-foot";
      var footerLeft = document.createElement("div");
      footerLeft.className = "dt-foot-left";
      var count = document.createElement("div");
      count.className = "dt-count";
      count.setAttribute("aria-live", "polite");
      var pagination = document.createElement("nav");
      pagination.className = "dt-pager";
      pagination.setAttribute("aria-label", "Pagination");
      footerLeft.append(count, sizeLabel);
      footer.append(footerLeft, pagination);
      wrap.insertAdjacentElement("afterend", footer);

      var sortKey = -1;
      var sortDirection = 1;
      var page = 1;
      var pageSize = 10;

      table.querySelectorAll("thead th").forEach(function (header, index) {
        var label = header.textContent.trim();
        if (!label || header.querySelector("button") || header.querySelector('input[type="checkbox"]')) return;
        var button = document.createElement("button");
        button.type = "button";
        button.className = "payment-history-sort";
        button.textContent = label + " ";
        var indicator = document.createElement("span");
        indicator.setAttribute("aria-hidden", "true");
        indicator.textContent = "↕";
        button.appendChild(indicator);
        header.textContent = "";
        header.appendChild(button);
        header.dataset.sortIndex = String(index);
        header.setAttribute("aria-sort", "none");
        button.addEventListener("click", function () {
          if (sortKey === index) sortDirection *= -1;
          else {
            sortKey = index;
            sortDirection = 1;
          }
          render();
        });
      });

      function matchingRows() {
        var query = search ? search.value.trim().toLocaleLowerCase() : "";
        return rows.filter(function (row) {
          var matchesSearch = !query || row.textContent.toLocaleLowerCase().indexOf(query) !== -1;
          var matchesFilters = filters.every(function (filter) {
            var filterValue = filter.select.value;
            if (!filterValue) return true;
            var rowFilterValue = filter.key
              ? row.dataset[filter.key] || ""
              : (row.cells[filter.column]
                ? row.cells[filter.column].dataset.filterValue || row.cells[filter.column].textContent.trim()
                : "");
            return rowFilterValue.toLocaleLowerCase() === filterValue.toLocaleLowerCase();
          });
          return matchesSearch && matchesFilters;
        }).sort(function (left, right) {
          if (sortKey < 0) return rows.indexOf(left) - rows.indexOf(right);
          var leftCell = left.cells[sortKey];
          var rightCell = right.cells[sortKey];
          var leftValue = leftCell ? (leftCell.dataset.sortValue || leftCell.textContent).trim() : "";
          var rightValue = rightCell ? (rightCell.dataset.sortValue || rightCell.textContent).trim() : "";
          var comparison;
          if (table.tHead.rows[0].cells[sortKey].dataset.sortType === "number") {
            comparison = Number(leftValue.replace(/[^\d.-]/g, "")) - Number(rightValue.replace(/[^\d.-]/g, ""));
          } else if (table.tHead.rows[0].cells[sortKey].dataset.sortType === "date") {
            comparison = collator.compare(leftValue, rightValue);
          } else {
            comparison = collator.compare(leftValue, rightValue);
          }
          return comparison * sortDirection;
        });
      }

      function addPageButton(label, targetPage, disabled, current, iconDirection) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "dt-page";
        button.disabled = disabled;
        if (current) {
          button.setAttribute("aria-current", "page");
          button.setAttribute("aria-label", "Page " + label + ", current page");
        } else if (/^\d+$/.test(label)) {
          button.setAttribute("aria-label", "Go to page " + label);
        } else if (!disabled) {
          button.setAttribute("aria-label", label);
        }
        if (iconDirection) {
          var icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
          icon.setAttribute("width", "16");
          icon.setAttribute("height", "16");
          icon.setAttribute("viewBox", "0 0 24 24");
          icon.setAttribute("fill", "none");
          icon.setAttribute("stroke", "currentColor");
          icon.setAttribute("stroke-width", "2");
          icon.setAttribute("stroke-linecap", "round");
          icon.setAttribute("stroke-linejoin", "round");
          icon.setAttribute("aria-hidden", "true");
          var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
          path.setAttribute("d", iconDirection === "previous" ? "M15 6l-6 6 6 6" : "M9 6l6 6-6 6");
          icon.appendChild(path);
          if (iconDirection === "previous") button.appendChild(icon);
          button.appendChild(document.createTextNode(label));
          if (iconDirection === "next") button.appendChild(icon);
        } else {
          button.textContent = label;
        }
        button.addEventListener("click", function () {
          page = targetPage;
          render();
        });
        pagination.appendChild(button);
      }

      function render() {
        var filtered = matchingRows();
        footer.hidden = filtered.length === 0;
        var pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.min(page, pageCount);
        var start = (page - 1) * pageSize;
        var shownRows = filtered.slice(start, start + pageSize);
        var shownSet = new Set(shownRows);
        filtered.forEach(function (row) { body.appendChild(row); });
        rows.forEach(function (row) {
          if (filtered.indexOf(row) === -1) body.appendChild(row);
        });
        rows.forEach(function (row) { row.hidden = !shownSet.has(row); });
        count.innerHTML = filtered.length
          ? "Showing <b>" + (start + 1) + "–" + (start + shownRows.length) + "</b> of <b>" + filtered.length + "</b> records"
          : (pageSizeOnly ? "No customer records to show." : "No records match your search or filters.");
        pagination.replaceChildren();
        addPageButton("Previous", Math.max(1, page - 1), page === 1, false, "previous");
        if (pageCount <= 7) {
          for (var index = 1; index <= pageCount; index += 1) {
            addPageButton(String(index), index, false, index === page);
          }
        } else {
          var pageNumbers = new Set([1, pageCount, page - 1, page, page + 1]);
          if (page <= 3) [2, 3, 4].forEach(function (number) { pageNumbers.add(number); });
          if (page >= pageCount - 2) [pageCount - 3, pageCount - 2, pageCount - 1].forEach(function (number) { pageNumbers.add(number); });
          var orderedPages = Array.from(pageNumbers).filter(function (number) {
            return number >= 1 && number <= pageCount;
          }).sort(function (left, right) { return left - right; });
          var previousNumber = 0;
          orderedPages.forEach(function (number) {
            if (previousNumber && number - previousNumber > 1) {
              var ellipsis = document.createElement("span");
              ellipsis.className = "dt-page dt-ellipsis";
              ellipsis.setAttribute("aria-hidden", "true");
              ellipsis.textContent = "…";
              pagination.appendChild(ellipsis);
            }
            addPageButton(String(number), number, false, number === page);
            previousNumber = number;
          });
        }
        addPageButton("Next", Math.min(pageCount, page + 1), page === pageCount, false, "next");
        table.querySelectorAll("thead th[data-sort-index]").forEach(function (header) {
          var active = Number(header.dataset.sortIndex) === sortKey;
          header.setAttribute("aria-sort", active ? (sortDirection === 1 ? "ascending" : "descending") : "none");
          var icon = header.querySelector(".payment-history-sort span");
          if (icon) icon.textContent = active ? (sortDirection === 1 ? "↑" : "↓") : "↕";
        });
      }

      if (search) search.addEventListener("input", function () { page = 1; render(); });
      filters.forEach(function (filter) {
        filter.select.addEventListener("change", function () { page = 1; render(); });
      });
      pageSizeSelect.addEventListener("change", function () {
        pageSize = Math.max(1, Number(pageSizeSelect.value) || 10);
        page = 1;
        render();
      });
      render();
    });
  }

  function initProductEditDialog() {
    var dialog = document.getElementById("product-edit-dialog");
    if (!dialog) return;
    var form = dialog.querySelector("form");
    var idInput = form.querySelector('input[name="id"]');
    var nameInput = form.querySelector('input[name="name"]');
    var categoryInput = form.querySelector('select[name="category"]');
    var cycleInput = form.querySelector('select[name="billing_cycle"]');

    document.addEventListener("click", function (event) {
      var editButton = event.target.closest("[data-product-edit]");
      if (editButton) {
        idInput.value = editButton.dataset.id || "";
        nameInput.value = editButton.dataset.name || "";
        categoryInput.value = editButton.dataset.category || "";
        cycleInput.value = editButton.dataset.cycle || "";
        if (typeof dialog.showModal === "function") {
          dialog.showModal();
          nameInput.focus();
        }
        return;
      }
      if (event.target.closest("[data-close-product-edit]")) dialog.close();
    });

    if (dialog.dataset.open === "true" && typeof dialog.showModal === "function") {
      dialog.showModal();
      nameInput.focus();
    }
  }

  function init() {
    initSidebar();
    initMenus();
    initConfirmDialogs();
    initInvoiceEmailToolbar();
    initInvoiceBulkDelete();
    initOverdueAdminCheck();
    initAdminAlertStatus();
    initPasswordToggle();
    initCustomerFlash();
    initCustomerDomains();
    initCustomerGoogleWorkspaces();
    initInvoiceCustomerDomains();
    initInvoiceItems();
    initInvoicePaymentEntry();
    initInvoiceViewPaymentDialog();
    initProductEditDialog();
    initPaymentCurrencyLabel();
    initPaymentHistoryTable();
    initEnhancedTables();
    initFormValidation();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
