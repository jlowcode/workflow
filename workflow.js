/**
 * Workflow Javascript
 *
 * @copyright: Copyright (C) 2024
 * 
 */

define(['jquery', 'fab/fabrik'], function (jQuery, Fabrik) {
	'use strict';
	var FabrikWorkflow = new Class({

		Implements: [Events],

		statusName: {
			'verify': Joomla.JText._('PLG_FORM_WORKFLOW_VERIFY'),
			'approved': Joomla.JText._('PLG_FORM_WORKFLOW_APPROVED'),
			'pre-approved': Joomla.JText._('PLG_FORM_WORKFLOW_PRE_APPROVED'),
			'not-approved': Joomla.JText._('PLG_FORM_WORKFLOW_NOTE_APPROVED')

		},

		requestTypeText: {
			'add_record': Joomla.JText._('PLG_FORM_WORKFLOW_ADD_RECORD'),
			'edit_field_value': Joomla.JText._('PLG_FORM_WORKFLOW_EDIT_FIELD_RECORD'),
			'delete_record': Joomla.JText._('PLG_FORM_WORKFLOW_DELETE_RECORD')
		},

		elementsName: {
			'req_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQ_ID_LABEL'),
			'req_owner_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQ_OWNER_ID_LABEL'),
			'req_owner_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQ_OWNER_ID_LABEL'),
			'req_request_type_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_ID_LABEL'),
			'req_request_type_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_ID_LABEL'),
			'req_user_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_USER_ID_LABEL'),
			'req_user_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_USER_ID_LABEL'),
			'req_created_date': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_CREATED_DATE_LABEL'),
			'req_status': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_STATUS_LABEL'),
			'req_record_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_RECORD_ID_LABEL'),
			'req_list_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_LIST_ID_LABEL'),
			'req_reviewer_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_REVIEWER_ID_LABEL'),
			'req_revision_date': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL'),
			'req_comment': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_COMMENT_LABEL'),
			'req_file': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_FILE_LABEL'),
			'req_approval': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_LABEL'),
			'req_user_email': Joomla.JText._('PLG_FORM_WORKFLOW_REQ_OWNER_LABEL'),
		},

		tableHeadins: {
			'req_request_type_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_ID_LABEL'),
			'req_user_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_USER_ID_LABEL'),
			'req_created_date': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_CREATED_DATE_LABEL'),
			'req_owner_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQ_OWNER_ID_LABEL'),
			'req_reviewer_name': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_REVIEWER_ID_LABEL'),
			'req_revision_date': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_REVISION_DATE_LABEL'),
			'req_status': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_STATUS_LABEL'),
			'req_record_id': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_RECORD_ID_LABEL'),
			'req_approval': Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_LABEL'),
		},

		requestsStatus: {
			'verify': 'Verify',
			'approved': 'Approved',
			'pre-approved': 'Pre-Approved',
			'not-approved': 'Not Approved'
		},

		initialize: function (options) {
			var self = this;
			var block = Fabrik.getBlock('form_8');
			this.options = options;
			this.options.actualPage = 1;
			// When the page is ready
			jQuery(document).ready(function () {
				// Get the modal
				var modal = jQuery('#modal')[0];
				self.modal = modal;
				self.loadRequestList(modal, 'verify');

				// Search event
				var inputSearch = jQuery("#searchTable");
				inputSearch.on('keyup', function (event) {
					if (event.target.value === "") {
						self.loadRequestList(modal, 'verify', 1);
					} else {
						if (event.target.value.length > 2) {
							self.loadRequestList(modal, 'verify', 1, event.target.value);
						}
					}
				});

				// Get the button that opens the modal
				// var btn = jQuery('#myBtn')[0];

				// Get the <span> element that closes the modal
				var span = jQuery('.modalCloseBtn')[0];

				// When the user clicks on <span> (x), close the modal
				span.onclick = function () {
					modal.style.display = "none";
				};

				// When the user clicks anywhere outside of the modal, close it
				window.onclick = function (event) {
					if (event.target == modal) {
						modal.style.display = "none";
					}
				};


				// var elements = document.getElementsByClassName('buttonClass');
				// for(var i = 0; i < elements.length; i++) {
				// 	var button = elements[i];
				//
				// 	jQuery(button).on('click', function() {
				// 		var req_id = jQuery(this).attr('req-id');
				// 		modal.style.display = "block";
				// 		// modal.css('overflow', 'auto');
				// 		self.getRequest(req_id).done(function (data) {
				// 			var formData = JSON.stringify(data);
				// 			self.setForm(self.buildForm(JSON.parse(formData)[0]), modal, data, req_id);
				// 		});
				// 	})
				// }

				if ('show_request_id' in self.options) {

					const requestId = parseInt(self.options.show_request_id, 10);
					self.getRequest(requestId).done(function (data) {
						var objData = JSON.decode(data);

						self.setForm(self.buildFormTest(objData[0]), modal, [objData[0]], requestId);
						modal.show();
					});
					// // Get the request
					// getRequest: function (req_id)
					// // getRecord: function (req_list_id, req_record_id)
					// self.setForm(self.buildForm(request), modal, [request], request['req_id']);
					// modal.show();
				}

				var dataRow = document.getElementsByClassName('fabrik_row');
				Array.from(dataRow).each(function (row) {

					// BUTTON REPORT 
					var btnGroup = row.getElementsByClassName('dropdown-menu');
					let li = document.createElement("li");
					li.setAttribute('class', 'nav-link')

					let report = document.createElement("a");
					report.classList.add('btn-default-delete');
					report.setAttribute('data-loadmethod', 'xhr')
					report.setAttribute('data-list', row.offsetParent.id)
					report.setAttribute('list-row-ids', row.id.split('_')[4] + ':' + row.id.split('_')[6])
					report.setAttribute('data-rowid', 'xhr')
					report.setAttribute('target', '_self')
					report.setAttribute('title', 'Reportar')
					//report.setAttribute('onclick', 'reportAbuse(' + row.id.split('_')[4] + ',' + row.id.split('_')[6] + ')')
					report.innerHTML = '<span><i class="fas fa-exclamation-triangle fa-sm" style="color: #8c8c8c;"></i></span> Reportar';
					
					li.appendChild(report)
					btnGroup[0].appendChild(li);

					// REMOVE DELETAR PADRAO
					jQuery('.dropdown-menu a.delete').parent().remove()

					// ADICIONA TOOTIPS PARA CAMPOS VAZIOS
					var fields = jQuery('.fabrik_element');
					Object.keys(fields).forEach(function (key) {
						if (fields[key].outerText == '') {
							fields[key].parentElement.setAttribute('data-bs-toggle', "tooltip")
							fields[key].parentElement.setAttribute('data-bs-placement', "top")
							fields[key].parentElement.setAttribute('title', "Completar ou corrigir esses dados")
						};
					});
				});

				jQuery("a.btn-default-delete").on("click", function (e) {
					const loadImg = jQuery('<div style=" display: flex; position: fixed; background: rgba(0,0,0,0.5);width: 100%;top: 0;height: 100vh;margin: auto;"><img style="margin: auto;" src="https://mir-s3-cdn-cf.behance.net/project_modules/disp/35771931234507.564a1d2403b3a.gif"></div>');
					jQuery('body').append(loadImg);
					var listRowIds = this.attributes['list-row-ids'].value

					jQuery.ajax({
						'url': '',
						'method': 'get',
						'data': {
							'options': self.options,
							'listRowIds': listRowIds,
							'option': 'com_fabrik',
							'task': 'plugin.pluginAjax',
							'plugin': 'workflow',
							'method': 'onReportAbuse',
							'g': 'form',
						},
						success: function (data) {
							location.reload();
						}
					});
				});
			})

			//Request type select
			var requestTypeSelect = jQuery('#requestTypeSelect');

			for (var chave in self.requestsStatus) {
				if (chave == 'verify') {
					requestTypeSelect.append('<option selected="selected" value="' + chave + '">' + self.requestsStatus[chave] + '</option>');
				} else {
					requestTypeSelect.append('<option value="' + chave + '">' + self.requestsStatus[chave] + '</option>');
				}
			}

			requestTypeSelect.change(function () {
				var selected = jQuery(this).children("option:selected").val();

				self.loadRequestList(self.modal, selected, 1);
			});


			// Order by
			var orderByDropdownItens = jQuery('#orderBySelect');

			for (var chave in self.tableHeadins) {
				if (chave == 'req_created_date') {
					orderByDropdownItens.append('<option selected="selected" value="' + chave + '">' + self.tableHeadins[chave] + ' - ASC' + '</option>');
					orderByDropdownItens.append('<option value="' + chave + '_desc' + '">' + self.tableHeadins[chave] + ' - DESC' + '</option>');
				} else {
					orderByDropdownItens.append('<option value="' + chave + '">' + self.tableHeadins[chave] + ' - ASC' + '</option>');
					orderByDropdownItens.append('<option value="' + chave + '_desc' + '">' + self.tableHeadins[chave] + ' - DESC' + '</option>');
				}

			}

			orderByDropdownItens.change(function () {
				var selected = jQuery(this).children("option:selected").val();
				// get the requests type selected - approved/pre-approved ect
				var typeSelected = jQuery(requestTypeSelect).children("option:selected").val();
				self.loadRequestList(self.modal, typeSelected, self.options.actualPage, null, selected);
			});

		},

		onGetSessionToken: function () {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetSessionToken',
					'g': 'form',
				}
			});
		},

		setPagination: function (actualPage = 1) {
			var self = this;
			self.options.actualPage = actualPage;
			var paginationElement = jQuery('#workflow-pagination');
			paginationElement.empty();
			var paginationUl = jQuery('<ul class="pagination-list"></ul>');
			var requestsCount = this.options.requestsCount;
			var pageCount = requestsCount / 5;
			const cursorPointer = "cursor: pointer;";
			pageCount = Math.ceil(pageCount);

			const startButtonPagination = jQuery('<a class="page-link" id="start-button" rel="noreferrer" target="_blank" type="button">' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_START_LABEL') + '</a>');
			const prevButtonPagination = jQuery('<a class="page-link" id="pagination-prev" rel="noreferrer" target="_blank" type="button">' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_PREV_LABEL') + '</a>');

			const nextButtonPagination = jQuery('<a class="page-link" id="pagination-next" rel="noreferrer" target="_blank" type="button">' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_NEXT_LABEL') + '</a>');
			const endButtonPagination = jQuery('<a class="page-link" id="pagination-end" rel="noreferrer" target="_blank" type="button">' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_END_LABEL') + '</a>');

			if (actualPage == 1) {
				paginationUl.append(jQuery('<li class="page-item"></li>').append(startButtonPagination));
				paginationUl.append(jQuery('<li class="page-item"></li>').append(prevButtonPagination));
			} else {
				// @TODO - PAGINATION Start prev
				startButtonPagination.on('click', function () {
					var selected = jQuery('#requestTypeSelect')[0].value
					self.loadRequestList(self.modal, selected, 1);
					// self.loadRequestList(self.modal, 'verify', 1);
				});
				prevButtonPagination.on('click', function () {
					if (actualPage != 1) {
						var selected = jQuery('#requestTypeSelect')[0].value
						self.loadRequestList(self.modal, selected, actualPage - 1);
						// self.loadRequestList(self.modal, 'verify', actualPage-1);
					}

				});
				startButtonPagination.attr("style", cursorPointer);
				prevButtonPagination.attr("style", cursorPointer);
				paginationUl.append(jQuery('<li></li>').append(startButtonPagination));
				paginationUl.append(jQuery('<li></li>').append(prevButtonPagination));
			}

			for (var i = 1; i <= pageCount; i++) {

				const pageButton = jQuery('<a class="page-link" rel="noreferrer" target="_blank" type="button">' + i + '</a>');



				if (actualPage == i) {
					paginationUl.append(jQuery('<li class="active"></li>').append(pageButton));
				} else {
					var teste = i;
					// Event on click pagination numbers
					(function (index) {
						pageButton.attr("style", cursorPointer);
						pageButton.on('click', function () {
							// self.setPagination(index);
							var selected = jQuery('#requestTypeSelect')[0].value
							self.loadRequestList(self.modal, selected, index);
							// self.loadRequestList(self.modal, 'verify', index);

						});
					})(i);


					paginationUl.append(jQuery('<li></li>').append(pageButton));
				}
			}
			if (actualPage == pageCount || isNaN(pageCount)) {
				paginationUl.append(jQuery('<li class="page-item"></li>').append(nextButtonPagination));
				paginationUl.append(jQuery('<li class="page-item"></li>').append(endButtonPagination));
			} else {
				// @TODO - PAGINATION NEXT END
				nextButtonPagination.on('click', function () {
					if (actualPage != pageCount) {
						var selected = jQuery('#requestTypeSelect')[0].value
						self.loadRequestList(self.modal, selected, actualPage + 1);
						// self.loadRequestList(self.modal, 'verify', actualPage+1);
					}
				});
				endButtonPagination.on('click', function () {
					var selected = jQuery('#requestTypeSelect')[0].value
					self.loadRequestList(self.modal, selected, pageCount);
					// self.loadRequestList(self.modal, 'verify', pageCount);
				});
				nextButtonPagination.attr("style", cursorPointer);
				endButtonPagination.attr("style", cursorPointer);
				paginationUl.append(jQuery('<li></li>').append(nextButtonPagination));
				paginationUl.append(jQuery('<li></li>').append(endButtonPagination));
			}

			paginationElement.append(paginationUl);
		},

		loadRequestList: function (modal, type, page = 1, search = null, orderBy = 'req_created_date') {

			orderBy = jQuery('#orderBySelect').val();

			var self = this;

			if (this.options.wfl_action == 'list_requests') {
				this.getRequestsList(type, 5, start, search, 1).done(function (response) {
					self.options.requestsCount = response;
					self.setPagination(page);

				});

			}
			var self = this;
			var start;
			var table = jQuery("#tblEntAttributes");
			var tableBody = jQuery("#tblEntAttributes tbody");
			const loadImg = jQuery('<img style="display:block; margin: auto;" src="https://mir-s3-cdn-cf.behance.net/project_modules/disp/35771931234507.564a1d2403b3a.gif">');

			tableBody.empty();
			tableBody.empty();
			var empty = jQuery("<tr><td  colspan='10'>Nenhum registro encontrado</td></tr>");
			tableBody.append(empty);
			if (page == 1) {
				start = 0;
			} else {
				start = ((page - 1) * 5);
			}
			self.getRequestsList(type, 5, start, search, 0, orderBy).done(function (response) {
				var requests = JSON.decode(response);

				if (requests.length !== 0) {
					tableBody.empty();
				}
				self.options.requestsCount = requests['requestCount'];

				jQuery.each(requests[type], function (i, item) {
					var request = item.data;
					var newRowContent = jQuery("<tr></tr>");
					var buttonOpenModal = jQuery("<td><a style='width: 100%;' class='btn'><i data-isicon=\"true\" class=\"icon-search \"></a></td>");

					jQuery.each(self.tableHeadins, function (key, value) {
						// If field is null, don't show anything
						if (request[key]) {
							if (key == 'req_status') {
								var d = self.statusName[request[key]];
								newRowContent.append("<td>" + d + "</td>");
							} else if (key == 'req_request_type_name') {
								var d = self.requestTypeText[request[key]];
								newRowContent.append("<td>" + d + "</td>");
							} else {
								newRowContent.append("<td>" + request[key] + "</td>");
							}
						} else {
							newRowContent.append("<td></td>");
						}

					});

					buttonOpenModal.on('click', function () {
						self.setForm(self.buildFormTest(request), modal, [request], request['req_id']);
						modal.show();
					});

					newRowContent.append(buttonOpenModal);
					tableBody.append(newRowContent);
				});
			});
		},

		getRequestsList: function (req_status, length = 5, start = 0, search = "", count = "0", orderBy = 'req_created_date') {
			var sequence = "asc";
			orderBy = jQuery('#orderBySelect').val();
			// if is DESC ordered
			if (orderBy.indexOf("_desc") !== -1) {
				orderBy = orderBy.replace("_desc", "");
				sequence = "desc";
			}
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'req_status': req_status,
					'wfl_action': this.options.wfl_action,
					'approve_for_own_records': this.options.user.approve_for_own_records,
					'list_id': this.options.listId,
					'user_id': this.options.user.id,
					'allow_review_request': this.options.allow_review_request,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetRequestList',
					'g': 'form',
					'length': length,
					'start': start,
					'search': search,
					'count': count,
					'order_by': orderBy,
					'sequence': sequence
				}
			});
		},

		canApproveRequests: function (form_data) {
			var canApproveRequests = this.options.user.canApproveRequests;

			if (form_data['req_owner_id'] === this.options.user.id && this.options.user.approve_for_own_records == 1) {
				// ALTERAÇÃO
				if (form_data['req_request_type_name'] == "edit_field_value" || form_data['req_request_type_name'] == "delete_record") {
					canApproveRequests = true;
				} else {
					canApproveRequests = false;
				}
			} else if (form_data['req_user_id'] === this.options.user.id) {
				canApproveRequests = false;
			}
			return canApproveRequests;
		},

		setForm: function (form, modal, formData, request_id) {
			var self = this;
			var jModalBody = jQuery(jQuery(modal).find('.modalBody')[0]);
			jModalBody.empty();

			var commentContainer = jQuery("<div class='mt-2'></div>");
			var fileContainer = jQuery("<div class='mt-2'></div>");
			var commentLabel = jQuery("<p>" + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_COMMENT_LABEL') + "</p>");
			var commentTextArea = jQuery("<textarea style='width: 100%;height: 5rem;' id='commentTextArea'></textarea>");
			var approveSection = jQuery("<div class='mt-2 mb-4'></div>");
			var approveSectionTitle = jQuery("<h2>" + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_LABEL') + "</h2>");
			var uploadFileApproveLabel = jQuery("<p>" + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_FILE_LABEL') + "</p>");
			var uploadFileApprove = jQuery("<input type='file' name='uploadFileApprove' id='uploadFileApprove'>");

			var yesno = jQuery("<div class=\"btn-group btn-group-toggle\" data-toggle=\"buttons\">\n" +
				"  <label class=\"btn btn-success\">\n" +
				"    <input type=\"radio\" name=\"yesnooptions\" id=\"approveButtonYes\" value=\"yes\" autocomplete=\"off\" checked> Sim\n" +
				"  </label>\n" +
				"  <label class=\"btn btn-danger\">\n" +
				"    <input type=\"radio\" name=\"yesnooptions\" id=\"approveButtonNo\" value=\"no\" autocomplete=\"off\"> Não \n" +
				"  </label>\n" +
				"</div>");

			var approvedCheckboxContainer = jQuery("<p class='mt-2'>" + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_LABEL') + " </p>");
			approvedCheckboxContainer.append(yesno);

			commentContainer.append(commentLabel);
			commentContainer.append(commentTextArea);

			fileContainer.append(uploadFileApproveLabel);
			fileContainer.append(uploadFileApprove);

			approveSection.append(approveSectionTitle);
			approveSection.append(commentContainer);
			approveSection.append(fileContainer);
			approveSection.append(approvedCheckboxContainer);

			jModalBody.append(form);
			// @TODO - Verificar se pode aprovar
			if (formData[0]['req_status'] == 'verify') {
				if (self.canApproveRequests(formData[0])) {
					var approveButton = jQuery('<button class="btn btn-primary" id="approveButton">' +
						Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_APPROVAL_SECTION_SAVE_LABEL') + '</button>');

					// @TODO RESOLVER DE OUTRA FORMA
					setTimeout(() => { form.append(approveSection); }, 2000);
					form.append(approveSection);
					jQuery(approveButton).on('click', function () {
						const requestType = formData[0]['req_request_type_id'];

						var uploadFileInput = jQuery(form).find("#uploadFileApprove");
						var approved = jQuery("input[name='yesnooptions']:checked").val() == "yes" ? true : false;

						jModalBody.empty();
						jModalBody.append(jQuery('<h3>Carregando... </h3>'));

						if (approved) {
							formData[0]['req_approval'] = '1';
						} else {
							formData[0]['req_approval'] = '0';
						}

						if (jQuery(form).find("#commentTextArea")[0]) {

							formData[0]['req_comment'] = jQuery(form).find("#commentTextArea")[0].value;
						}

						// remove raws from the record data
						var recordData = JSON.decode(formData[0]['form_data']);
						for (var chave in recordData) {
							if (!recordData.hasOwnProperty(chave)) continue;
							if (chave.indexOf("_raw") !== -1) {
								delete recordData[chave];
							}
						}

						if (approved) {
							if (requestType == 3) {
								// const rowId = JSON.decode(formData[0]['form_data'])[0]['rowid'];
								const rowId = formData[0].req_record_id;
								const listId = formData[0].req_list_id;
								self.deleteRecord(rowId, listId);
							} else {

								self.createUpdateRecord(formData);
							}
						}

						if (uploadFileInput.val()) {
							self.uploadFile(uploadFileInput).done(function (response) {
								formData[0]['req_file'] = response;
								jQuery.ajax({
									'url': '',
									'method': 'get',
									'data': {
										'formData': formData,
										'sendMail': self.options.sendMail,
										'option': 'com_fabrik',
										'format': 'raw',
										'task': 'plugin.pluginAjax',
										'plugin': 'workflow',
										'method': 'ProcessRequest',
										'g': 'form',
									},
									success: function (data) {
										modal.style.display = "none";
										alert('Concluído');
										document.location.reload(true);
									}
								});
							});
						} else {

							jQuery.ajax({
								'url': '',
								'method': 'get',
								'data': {
									'formData': formData,
									'sendMail': self.options.sendMail,
									'option': 'com_fabrik',
									'format': 'raw',
									'task': 'plugin.pluginAjax',
									'plugin': 'workflow',
									'method': 'ProcessRequest',
									'g': 'form',
								},
								success: function (data) {
									modal.style.display = "none";
									alert('Concluído');
									document.location.reload(true);
								}
							});

						}
					});
					jModalBody.append(approveButton);
				}
			}
		},

		// delete a record
		// deleteRecord: function(rowId, listName) {
		//     var self = this;
		//     return jQuery.ajax({
		//         'url'   : 'index.php',
		//         'method': 'get',
		//         'data'  : {
		//             'list_name': self.options.listName,
		//             'record_id': rowId,
		//             'option'   : 'com_fabrik',
		//             'format'   : 'raw',
		//             'task'     : 'plugin.pluginAjax',
		//             'plugin'   : 'workflow',
		//             'method'   : 'DeleteRecord',
		//             'g'        : 'form',
		//         }
		//     });
		// },

		deleteRecord: function (rowId, listId) {
			jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'option': 'com_fabrik',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'onDeleteRow',
					'g': 'form',
					'rowId': '{' + rowId + ':' + rowId + '}',
					'listId': listId,
				},
				success: function (data) {
				}
			});
		},

		// Uses the process() function of fabrik's controller to
		// create or update a record
		createUpdateRecord: function (formData) {
			this.getSessionToken().done(function (token) {
				var recordData = JSON.decode(formData[0]['form_data']);
				recordData[token] = "1";
				recordData['review'] = true;
				recordData['req_id'] = formData[0]['req_id'];
				jQuery.ajax({
					type: "POST",
					url: "",
					data: recordData
				}).done(function (retorno) {
					// console.log(retorno);
				});
			});
		},

		getLastRecordFormData: function (req_record_id) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'req_record_id': req_record_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetLastRecordFormData',
					'g': 'form',
				},
			});
		},

		compareRecords: function (lastRecord, newRecord) {
			var self = this;
			// iterates over new record finding correspondent on
			// las record, store if doesn't exists or if is not the same value
			const listName = this.options.listName;
			var changedProperties = {};
			for (var key in newRecord) {
				// skip loop if the property is from prototype
				if (!newRecord.hasOwnProperty(key) || !(key.indexOf(listName + '_') !== -1)) continue;

				// verify if the property exists on the last record
				// if exists verify if has changed
				if (lastRecord.hasOwnProperty(key)) {
					// verificar se mudou o value
					if (!self.isEqual(newRecord[key], lastRecord[key])) {
						var obj = {};
						obj['last'] = lastRecord[key];
						obj['new'] = newRecord[key];
						changedProperties[key] = obj;
					}
				} else {
					var obj = {};
					obj['last'] = lastRecord[key];
					obj['new'] = newRecord[key];
					changedProperties[key] = obj;
				}
			}
			return changedProperties;
		},

		// Compares if two arrays/objects are equals
		isEqual: function (e1, e2) {
			const e1Json = JSON.encode(e1);
			const e2Json = JSON.encode(e2);
			if (e1Json === e2Json)
				return true;
			return false;
		},

		renderFiles: function (id, fileList) {
			// Creates the div
			var div = jQuery('<div></div>');
			if (id) {
				var label = jQuery('<p>' + id + '</p>');
				div.append(label);
			}
			for (var key in fileList) {
				if (!fileList.hasOwnProperty(key)) continue;
				var link = jQuery('<p><a target="_blank" href="' + this.options.root_url + fileList[key] + '">' + fileList[key] + ' </a></p>');
				div.append(link);
			}

			return div;
		},

		renderFilesOriginalRequest: function (id, originalFileList, requestFileList) {
			var self = this;
			// Creates the div
			var containerDiv = jQuery('<div></div>');
			containerDiv.attr('style', 'display: flex;');
			const label = jQuery('<p>' + id + '</p>');
			const originalLabel = jQuery('<p>' + id + '_original</p>');

			var originalImages = jQuery('<div></div>');
			var requestImages = jQuery('<div></div>');


			originalImages.append(originalLabel);
			originalImages.attr('style', 'max-width: 50%; border-color: #e0e0e5; border-width: 1px; border-radius: 10px; border-style: solid; margin: 4px; padding: 8px;');
			originalFileList.forEach(function (element, index) {
				const link = jQuery('<p style="overflow-wrap: break-word;"><a target="_blank" href="' + self.options.root_url + element.value + '">' + element.value + ' </a></p>');
				originalImages.append(link);
			});

			requestImages.append(label);
			requestImages.attr('style', 'max-width: 50%; border-color: #e0e0e5; border-width: 1px; border-radius: 10px; border-style: solid; margin: 4px; padding: 8px;');
			Object.values(requestFileList).forEach(function (element, index) {
				const link = jQuery('<p style="overflow-wrap: break-word;"><a target="_blank" href="' + self.options.root_url + element + '">' + element + ' </a></p>');
				requestImages.append(link);
			});

			containerDiv.append(originalImages);
			containerDiv.append(requestImages);

			return containerDiv;
		},

		uploadFile: function (uploadFileInput) {
			if (uploadFileInput.val()) {
				var file_data = jQuery(uploadFileInput[0]).prop('files')[0];
				var form_data = new FormData();
				form_data.append('file', file_data);

				return jQuery.ajax({
					url: 'http://localhost/PITT/fabrik/plugins/fabrik_form/workflow/uploadFile.php',   // point to server-side PHP script
					dataType: 'text',                                                                       // what to expect back from the PHP script, if anything
					cache: false,
					contentType: false,
					processData: false,
					data: form_data,
					type: 'post',
					success: function (php_script_response) {
						// console.log(php_script_response); // display response from the PHP script, if any
					}
				});
			}
		},

		createInput: function (labelText, value, id) {
			// Creates the div
			var div = jQuery('<div></div>');
			// Creates the label with id
			var label = jQuery('<label for="' + id + '"> </label>')
			// Setting label text
			jQuery(label).html(labelText);
			// Creating input
			var input = jQuery('<input id="' + id + '" type="text" disabled/>');
			// Setting input value
			jQuery(input).val(value);
			// Appending label to div
			div.append(label);
			div.append(input);
			return div;
		},

		createInputsBeforeAfter: function (key, originalValue, newValue) {
			var self = this;
			const originalNewInputContainer = jQuery("<div></div>");
			originalNewInputContainer.attr('style', 'display: flex;');
			const inputContainer = self.createInput(key, newValue, key);
			const inputOriginalContainer = self.createInput(key + '_original', originalValue, key + '_original');
			inputContainer[0].style.paddingLeft = "5px";
			inputOriginalContainer[0].style.paddingLeft = "5px";
			inputContainer[0].style.flex = "1";
			inputOriginalContainer[0].style.flex = "1";
			originalNewInputContainer.append(inputOriginalContainer);
			originalNewInputContainer.append(inputContainer);
			return originalNewInputContainer;
		},

		buildFormTest: function (data) {
			var self = this;
			// Creates the form
			var form = jQuery('<form></form>');

			// Container to the Request Data, such as
			// [req_user_id. req_created_data, ...]
			var requestInputsContainer = jQuery('<div></div>');

			// Set a title to the container
			if (data['req_request_type_id'] == 1) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_ADD_TEXT') + '<h2><hr />');
			} else if (data['req_request_type_id'] == 2) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_EDIT_TEXT') + '<h2><hr />');
			} else if (data['req_request_type_id'] == 3) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_DELETE_TEXT') + '<h2><hr />');
			}
			requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_DATA_LABEL') + '<h2>');

			// Iterates over data creating a input form for each entry
			// and appending it to the container
			for (var key in data) {
				if (key == 'form_data')
					continue;
				if (data[key]) {
					switch (key) {
						case 'req_request_type_id':
						case 'req_id':
						case 'req_revision_date':
						case 'req_user_email':
						case 'req_list_id':
							continue;
							break;
						case 'req_file':
							var div = jQuery('<div></div>');
							var label = jQuery('<p>' + self.elementsName[key] + '</p>');
							div.append(label);
							var link = jQuery('<p><a target="_blank" href="' + this.options.root_url + data[key] + '">' + data[key] + ' </a></p>');
							div.append(link);
							requestInputsContainer.append(div);
							break;

						case 'req_owner_id':
							continue;
							break;

						case 'req_user_id':
							continue;
							break;

						case 'req_status':
							var d = this.statusName[data[key]];
							const inputContainerA = self.createInput(self.elementsName[key], d, key);
							requestInputsContainer.append(inputContainerA);
							break;

						case 'req_request_type_name':
							var d = this.requestTypeText[data[key]];
							const inputContainerB = self.createInput(self.elementsName[key], d, key);
							requestInputsContainer.append(inputContainerB);
							break;
						case 'req_record_id':
							var div = jQuery('<div></div>');
							var label = jQuery('<label for="' + self.elementsName[key] + '">' + self.elementsName[key] + ' </label>')
							div.append(label);
							var link = jQuery('<div class="input-group mb-3"><input type="text" class="form-control" placeholder="" disabled="" value="'+data[key]+'"><a target="_blank" href="'+form[0].baseURI.split('?')[0].replace('list','details')+'/'+data[key]+'"><button class="btn btn-primary h-100" type="button" id="'+self.elementsName[key]+'">Vizualizar</button></a></div>')
							div.append(link);
							requestInputsContainer.append(div);
							break;
						default:
							const inputContainer = self.createInput(self.elementsName[key], data[key], key);
							requestInputsContainer.append(inputContainer);
							break;
					}
				}
			}

			// Parsing the json from form_data to an object
			var formData = JSON.parse(data['form_data']);


			const listName = self.options.listName;

			// Container to the new/edited data of the request
			var formDataInputsContainer = jQuery('<div></div>');
			formDataInputsContainer.attr('class', 'formDataInputsContainer mt-2');
			formDataInputsContainer.attr('style', 'dispay: flex;');
			formDataInputsContainer.attr('style', 'flex-direction: column;');
			formDataInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_RECORD_DATA_LABEL') + '<h2>');
			formDataInputsContainer.css("background-color", "#e3e3e3");
			formDataInputsContainer.css("padding", "10px");

			// Append the request data to the form
			form.append(requestInputsContainer);
			form.append(formDataInputsContainer);

			if (data['req_request_type_id'] == "delete_record" || data['req_request_type_id'] == 3) {
				// delete record
				// const recordId = formData.rowid;
				const recordId = data['req_record_id'];

				this.getElementsType(data['req_list_id']).done(function (elementsTypes) {
					// const link = self.options.root_url + "index.php/" + listName + "/details/" + self.options.listId + "/" + recordId;
					const link = self.options.root_url + "component/fabrik/details/" + self.options.listId + "/" + recordId;
					formDataInputsContainer.append("<a class='btn btn-outline-primary' href='" + link + "'  target='_blank'>Clique aqui</a> para ver o registro a ser deletado.");
					form.append(formDataInputsContainer);
				});
			} else {
				// Get elements types to render the data
				this.getElementsType(formData['listid']).done(function (elementsTypes) {
					var elementTypesObj = JSON.decode(elementsTypes);
					// add_record || edit_field_value
					if (data['req_request_type_id'] == "add_record" || data['req_request_type_id'] == 1) {
						formDataInputsContainer.append(self.buildAddDeleteRecordView(formData, elementTypesObj));
						form.append(formDataInputsContainer);
					} else if (data['req_request_type_id'] == "edit_field_value" || data['req_request_type_id'] == 2) {
						// edit record
						self.getLastRecordFormData(data['req_record_id']).done(function (lastRecordFormData) {
							var lastFormData = JSON.decode(lastRecordFormData);
							const obj = self.compareRecords(lastFormData, formData);
							var hasProperty = false;
							if (jQuery.isEmptyObject(lastFormData)) {
								hasProperty = false;
							} else {
								for (var k in obj) {
									// Se o objeto tiver alguma propriedade
									// ou seja, o formulário adicionado tem um log gravado anteriormente
									if (obj.hasOwnProperty(k)) {
										formDataInputsContainer.append(
											self.buildEditRecordView(lastFormData, formData, elementTypesObj));
										hasProperty = true;
										break;
									}
								}
							}
							if (!hasProperty) {
								formDataInputsContainer.append(self.buildAddDeleteRecordView(formData, elementTypesObj)).append(
									"<p>" + Joomla.JText._('PLG_FORM_WORKFLOW_LOG') + "</p>"
								);
							}
							// formDataInputsContainer.append(self.buildEditRecordView(lastFormData, formData, elementTypesObj));
						});
					}
				});
			}
			// Returns the form
			return form;
		},

		buildAddDeleteRecordView: function (formData, elementsTypes) {
			var self = this;
			var view = jQuery("<div></div>");
			const listName = this.options.listName;
			var repeatGroups = {};

			// Iterates form data
			for (var key in formData) {
				// If has raw continue iteration
				// Verify if is list element
				if (key.indexOf(listName) !== -1) {
					// Ultimo raplace (.replace("-auto-complete", "")) serve para remover o sufixo q provavelmente é utilizado
					// pela implementação da Karine no databasejoin
					const onlyElementKey = key.replace(listName + "___", "").replace("_raw", "").replace("_value", "").replace("-auto-complete", "");
					if (key.indexOf("_raw") !== - 1) continue;
					// If has value ignore ids
					if (formData.hasOwnProperty(listName + "___" + onlyElementKey + "_value") && !(key.indexOf("_value") !== - 1)) continue;
					var obj = formData[key];
					// Verify if is repeat group
					const isRepeatGroup = key.indexOf('repeat');
					if (isRepeatGroup !== -1) {
						repeatGroups[key] = obj;
					} else if (obj.hasOwnProperty("crop")) {
						var filesProcessed = this.processFiles({ key: obj });
						view.append(this.renderFiles(key, filesProcessed));
					} else {
						// Get value if owner id
						if (elementsTypes[onlyElementKey] != undefined) {
							if (elementsTypes[onlyElementKey]['plugin'] == "user") {
								//onGetUserValue
								jQuery.ajax({
									'url': '',
									'method': 'get',
									'data': {
										'user_id': obj[0],
										'option': 'com_fabrik',
										'format': 'raw',
										'task': 'plugin.pluginAjax',
										'plugin': 'workflow',
										'method': 'GetUserValue',
										'g': 'form',
									},
									success: function (data) {
										view.append(self.createInput(onlyElementKey, data, onlyElementKey));
									}
								});
							} else {
								// Verify if is array
								if (Array.isArray(obj)) {
									view.append(this.buildMultipleElementView(obj, onlyElementKey));
								} else {
									view.append(this.createInput(onlyElementKey, formData[key], onlyElementKey));
								}
							}
						}
					}
				}
			}
			var groups = this.processRepeatGroups(repeatGroups);
			for (const groupKey in groups) {
				const groupArray = groups[groupKey];
				view.append(this.buildRepeatGroupView(groupArray, groupKey));
			}
			return view;
		},

		buildEditRecordView: function (lastFormData, newFormData, elementsTypes) {
			var self = this;
			var repeatGroups = {};
			var view = jQuery("<div></div>");
			const listName = this.options.listName;

			const changedProperties = self.compareRecords(lastFormData, newFormData);

			for (var key in changedProperties) {
				// If is not own property continue to next iteration
				if (!changedProperties.hasOwnProperty(key)) continue;
				// if is not raw continue to next iteration
				if (key.indexOf("_raw") === -1 && changedProperties.hasOwnProperty(key + "_raw")) continue;
				var obj = changedProperties[key];
				// const onlyElementKey = key.replace(listName+"___", "").replace("_raw", "");
				const onlyElementKey = key.replace(listName + "___", "").replace("_raw", "").replace("_value", "").replace("-auto-complete", "");
				// if is id continue to next iteration
				if (key.indexOf("_id") !== -1) continue;
				if ((key.indexOf("_raw") !== -1 || key.indexOf("-auto-complete") !== -1) && (changedProperties.hasOwnProperty(listName+"___"+onlyElementKey + "_value") )) continue;
				// Verify if is repeat group
				const isRepeatGroup = key.indexOf('repeat');
				if (isRepeatGroup !== -1) {
					repeatGroups[key] = obj;
				} else if (obj['new'].hasOwnProperty("crop")) {
					var lastFilesProcessed = this.processFiles({ key: obj['last'] }, true);
					var newFilesProcessed = this.processFiles({ key: obj['new'] });

					const container = jQuery("<div><p>" + onlyElementKey + "</p></div>");
					const containerFlex = jQuery("<div></div>");
					containerFlex.append("");
					containerFlex.attr('style', 'display: flex;');
					var newElement = jQuery("<div></div>");
					var originalElement = jQuery("<div></div>");

					originalElement.append(self.renderFiles(null, lastFilesProcessed));
					newElement.append(self.renderFiles(null, newFilesProcessed));

					newElement[0].style.paddingLeft = "5px";
					originalElement[0].style.paddingLeft = "5px";

					newElement[0].style.maxWidth = "50%";
					newElement[0].style.overflowWrap = "break-word";
					originalElement[0].style.maxWidth = "50%";
					originalElement[0].style.overflowWrap = "break-word";

					newElement[0].style.flex = "1";
					originalElement[0].style.flex = "1";
					containerFlex.append(originalElement);
					containerFlex.append(newElement);
					// view.append(this.buildMultipleElementView(obj, onlyElementKey));
					container.append(containerFlex);
					view.append(container);
				} else {
					// Verify if is array
					// Get value if owner id
					if (elementsTypes[onlyElementKey] != undefined) {
						if (elementsTypes[onlyElementKey]['plugin'] == "user") {
							//onGetUserValue
							jQuery.ajax({
								'url': '',
								'method': 'get',
								'data': {
									'last_user_id': obj['last'][0],
									'new_user_id': obj['new'][0],
									'option': 'com_fabrik',
									'format': 'raw',
									'task': 'plugin.pluginAjax',
									'plugin': 'workflow',
									'method': 'GetUserValueBeforeAfter',
									'g': 'form',
								},
								success: function (data) {
									const res = JSON.decode(data);
									// self.createInputsBeforeAfter(elementKey, originalString, requestString)
									view.append(self.createInputsBeforeAfter(onlyElementKey, res['last'], res['new']));
									// view.append(self.createInput(onlyElementKey, data, onlyElementKey));
								}
							});
						} else if (elementsTypes[onlyElementKey]['plugin'] == 'fileupload'){
							if (changedProperties[listName+'_FILE_'+key] && changedProperties[listName+'_FILE_'+key]['last'] != undefined){
									view.append(this.createInputsBeforeAfter(onlyElementKey, changedProperties[listName+'_FILE_'+key]['last']['name'], changedProperties[listName+'_FILE_'+key]['new']['name']));
									if (changedProperties[listName+'_FILE_'+key]['new']['type'].split("/")[0] == 'image'){
										view.append(self.renderImgs(changedProperties[listName+'_FILE_'+key]));
									}
							}
						} else {
							if (Array.isArray(obj['new']) || Array.isArray('last')) {
								const container = jQuery("<div></div>");
								const containerFlex = jQuery("<div></div>");
								containerFlex.append("");
								containerFlex.attr('style', 'display: flex;');
								var newElement = jQuery("<div><p>" + onlyElementKey + "</p></div>");
								var originalElement = jQuery("<div><p>" + onlyElementKey + "_original</p></div>");

								if (!obj['last'] || obj['last'] == undefined) {
									// originalElement.append("VAZIO");
								} else {
									originalElement.append(self.buildMultipleElementView(obj['last']));
								}
								newElement.append(self.buildMultipleElementView(obj['new']));

								newElement[0].style.paddingLeft = "5px";
								originalElement[0].style.paddingLeft = "5px";
								newElement[0].style.flex = "1";
								originalElement[0].style.flex = "1";
								containerFlex.append(originalElement);
								containerFlex.append(newElement);
								// view.append(this.buildMultipleElementView(obj, onlyElementKey));
								container.append(containerFlex);
								view.append(container);
							} else {
								// self.createInputsBeforeAfter(elementKey, originalString, requestString)
								view.append(this.createInputsBeforeAfter(onlyElementKey, obj['last'], obj['new']));
							}
						}
					}
				}
			}

			for (var key in repeatGroups) {
				const group = repeatGroups[key];
				const container = jQuery("<div><p>" + key + "</p></div>");
				const containerFlex = jQuery("<div></div>");
				containerFlex.append("");
				containerFlex.attr('style', 'display: flex;');
				var newElement = jQuery("<div></div>");
				var originalElement = jQuery("<div></div>");

				originalElement.append(self.buildRepeatGroupView(group['last']));
				newElement.append(self.buildRepeatGroupView(group['new']));

				newElement[0].style.paddingLeft = "5px";
				originalElement[0].style.paddingLeft = "5px";
				newElement[0].style.flex = "1";
				originalElement[0].style.flex = "1";
				containerFlex.append(originalElement);
				containerFlex.append(newElement);
				// view.append(this.buildMultipleElementView(obj, onlyElementKey));
				container.append(containerFlex);
				view.append(container);
			}
			return view;
		},

		buildMultipleElementView: function (element, key = null) {
			var div = jQuery("<div></div>");
			if (key) {
				div = jQuery("<div><p>" + key + "</p></div>");
			}
			var ul = jQuery("<ul class='workflow-ul-request'></ul>");
			element.forEach(function (one) {
				ul.append("<li>" + one + "</li>")

			});
			div.append(ul);
			return div;
		},

		buildRepeatGroupView: function (element, key) {
			var key = key ? key : "";
			var div = jQuery("<div><p>" + key + "</p></div>");
			var ul = jQuery("<ul></ul>");
			var empty = 0;
			element.forEach(function (one) {
				if (one !== "") {
					if (one !== Object(one)) {
						ul.append("<li>" + one + "</li>");
					} else {
						var innerUL = jQuery("<ul></ul>");
						for (const key in one) {
							if (!one.hasOwnProperty(key)) continue;
							innerUL.append("<li>" + one[key] + "</li>");
						}
						ul.append("Checkbox: ");
						ul.append(innerUL);
					}
				} else {
					empty++;
				}
			});
			div.append(ul);
			if (element.length > empty) {
				return div;
			}
			return null;
		},

		processRepeatGroups: function (repeatGroups) {
			var groups = {};
			for (const key in repeatGroups) {
				const indice = key.indexOf("___");
				const property = key.substr(0, indice);
				if (groups.hasOwnProperty(property)) {
					groups[property].append(repeatGroups[key]);
				} else {
					groups[property] = [];
					groups[property].append(repeatGroups[key]);
				}
			}
			// var groups = {};
			// for(const key in repeatGroups) {
			// 	const indice = key.indexOf("___");
			// 	const property = key.substr(0,indice);
			// 	const objName = key.substr(indice+3);
			// 	if(groups.hasOwnProperty(property)) {
			// 		groups[property][objName] = repeatGroups[key]
			// 	} else {
			// 		groups[property] = {};
			// 		groups[property][objName] = repeatGroups[key];
			// 	}
			// }
			return groups;
		},

		processFiles: function (files, edit = false) {
			var filesArray = [];
			if (edit) {
				filesArray = files;
			} else {
				for (const fileGroupKey in files) {
					if (!files.hasOwnProperty(fileGroupKey)) continue;
					const obj = files[fileGroupKey];
					const ids = Object.getOwnPropertyNames(obj['id']);
					ids.forEach(function (element, index) {
						filesArray.push(element);
					});
				}
			}
			return filesArray;
		},

		buildForm: function (data) {
			var self = this;
			// Creates the form
			var form = jQuery('<form></form>');

			// Container to the Request Data, such as
			// [req_user_id. req_created_data, ...]
			var requestInputsContainer = jQuery('<div></div>');

			// Set a title to the container
			if (data['req_request_type_id'] == 1) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_ADD_TEXT') + '<h2><hr />');
			} else if (data['req_request_type_id'] == 2) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_EDIT_TEXT') + '<h2><hr />');
			} else if (data['req_request_type_id'] == 3) {
				requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_TYPE_LABEL_DELETE_TEXT') + '<h2><hr />');
			}
			requestInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_REQUEST_DATA_LABEL') + '<h2>');

			// Iterates over data creating a input form for each entry
			// and appending it to the container
			for (var key in data) {
				if (key == 'form_data')
					continue;
				if (data[key]) {
					switch (key) {
						case 'req_request_type_id':
						case 'req_id':
						case 'req_revision_date':
						case 'req_list_id':
						case 'req_user_email':
							continue;
							break;
						case 'req_request_type_id':
							data['req_request_type_id'] = data['req_request_type_name'];
							delete data['req_request_type_name'];
							var inputLabel = self.elementsName[key];
							var inputId = key;
							const inputContainer5 = self.createInput(inputLabel, data['req_request_type_id'], inputId);
							requestInputsContainer.append(inputContainer5);
							// self.getRequestType(data[key]).done(function (response) {
							// 	const inputContainer = self.createInput(inputLabel, response[0].name, inputId);
							// 	requestInputsContainer.append(inputContainer);
							// });
							break;

						case 'req_file':
							var div = jQuery('<div></div>');
							var label = jQuery('<p>' + self.elementsName[key] + '</p>');
							div.append(label);
							var link = jQuery('<p><a target="_blank" href="' + this.options.root_url + data[key] + '">' + data[key] + ' </a></p>');
							div.append(link);
							requestInputsContainer.append(div);
							break;

						case 'req_owner_id':
							data['req_owner_id'] = data['req_owner_name'];
							delete data['req_owner_name'];
							const inputContainer1 = self.createInput('Dono do registro', data[key], key);
							requestInputsContainer.append(inputContainer1);
							break;

						case 'req_user_id':
							data['req_user_id'] = data['req_user_name'];
							delete data['req_user_name'];
							const inputContainer2 = self.createInput('Dono da requisição', data[key], key);
							requestInputsContainer.append(inputContainer2);
							break;
						case 'req_record_id':
							var div = jQuery('<div></div>');
							var label = jQuery('<label for="' + self.elementsName[key] + '">' + self.elementsName[key] + ' </label>')
							div.append(label);
							var link = jQuery('<div class="input-group mb-3"><input type="text" class="form-control" placeholder="" disabled="" value="'+data[key]+'"><a target="_blank" href="'+form[0].baseURI.split('?')[0].replace('list','details')+'/'+data[key]+'"><button class="btn btn-primary h-100" type="button" id="'+self.elementsName[key]+'">Vizualizar</button></a></div>')
							div.append(link);
							requestInputsContainer.append(div);
							break;
						default:
							const inputContainer = self.createInput(self.elementsName[key], data[key], key);
							requestInputsContainer.append(inputContainer);
							break;
					}
				}
			}

			// Parsing the json from form_data to an object
			var formData = JSON.parse(data['form_data']);

			// Container to the new/edited data of the request
			var formDataInputsContainer = jQuery('<div></div>');
			formDataInputsContainer.attr('class', 'formDataInputsContainer');
			formDataInputsContainer.attr('style', 'dispay: flex;');
			formDataInputsContainer.attr('style', 'flex-direction: column;');
			formDataInputsContainer.append('<h2>' + Joomla.JText._('PLG_FORM_WORKFLOW_RECORD_DATA_LABEL') + '<h2>');

			// Append the request data to the form
			form.append(requestInputsContainer);

			// add_record || edit_field_value
			if (data['req_request_type_id'] == "add_record") {
				// Iterates over formData creating inputs for each entry
				for (var key in formData) {
					if (formData[key]['is_files']) {
						delete formData[key]['is_files'];
						var links = self.renderFiles(key, formData[key]);
						formDataInputsContainer.append(links);
					} else {
						const inputContainer = self.createInput(key, formData[key], key);
						formDataInputsContainer.append(inputContainer);
					}

				}
				form.append(formDataInputsContainer);
			} else if (data['req_request_type_id'] == "edit_field_value") {
				self.getRecord(data['req_list_id'], data['req_record_id']).done(function (reqData) {
					var req_record_id = data['req_record_id'];

					for (var key in formData) {
						// @TODO - Mostrar imagem antes e depois
						if (formData[key]['is_files']) {
							const listName = self.options.listName;
							self.getFileUploadOriginals(listName, key, req_record_id).done(function (returnData) {
								var originalFileList = JSON.decode(returnData);
								var files = formData[key];
								delete files['is_files'];
								formDataInputsContainer.append(self.renderFilesOriginalRequest(key, originalFileList, files));
							});
							// var div = jQuery('<div></div>');
							// var label = jQuery('<p>' + key + '</p>');
							// div.append(label);
							// var link;
							// var files = formData[key];
							// delete files['is_files'];
							// for (var chave in files) {
							// 	link = jQuery('<p><a target="_blank" href="'+self.options.root_url+files[chave]+'">' + files[chave] + ' </a></p>');
							// 	div.append(link);
							// }
							// formDataInputsContainer.append(div);
						} else if (!(formData[key] == reqData[0][key])) {
							const elementKey = key;
							self.getElementsType(data['req_list_id']).done(function (data) {
								const allElementsType = JSON.decode(data);

								const currentElementData = allElementsType[elementKey];
								if (currentElementData['plugin'] == 'databasejoin') {
									var elementValue = JSON.decode(formData[elementKey]);
									// If is databasejoin verify if is multiple or single to get the elements
									if (currentElementData['database_join_display_type'] == 'multilist' ||
										currentElementData['database_join_display_type'] == 'checkbox') {
										const listName = self.options.listName;

										self.getDatabaseJoinMultipleElements(currentElementData['join_db_name'], listName,
											elementKey, req_record_id, currentElementData['join_val_column'],
											currentElementData['join_key_column'], formData[elementKey]).done(function (ajaxReturn) {
												const elementsData = JSON.decode(ajaxReturn);
												const elementsOriginal = elementsData.original;
												const elementsOriginalLength = elementsOriginal.length;
												const elementsRequest = elementsData.request;
												const elementsRequestLength = elementsRequest.length;
												var originalString = "";
												var requestString = "";

												// Create strings with ,
												elementsOriginal.forEach(function (element, index) {
													originalString += element.value;
													if (index != elementsOriginalLength - 1) {
														originalString += ', ';
													} else {
														originalString += '.';
													}
												});
												elementsRequest.forEach(function (element, index) {
													requestString += element.value;
													if (index != elementsRequestLength - 1) {
														requestString += ', ';
													} else {
														requestString += '.';
													}
												});

												formDataInputsContainer.append(self.createInputsBeforeAfter(elementKey, originalString, requestString));
											});
									} else {
										// Verify if trying to save multiple on single element
										if (Array.isArray(elementValue)) {
											alert('Warning: Element ' + elementKey + ' is single, but the request has multiple values.');
										} else {
											self.getDatabaseJoinSingleElements(currentElementData['join_db_name'],
												reqData[0][elementKey], formData[elementKey], currentElementData['join_val_column'],
												currentElementData['join_key_column']).done(function (data) {
													const result = JSON.decode(data);
													const newValue = result['new'][0]['value'];

													if ('original' in result) {

														const originalValue = result['original'][0]['value'];
														formDataInputsContainer.append(self.createInputsBeforeAfter(elementKey, originalValue, newValue));
													} else {
														formDataInputsContainer.append(self.createInputsBeforeAfter(elementKey, " ", newValue));
													}

												});
										}
									}

								} else {

									formDataInputsContainer.append(self.createInputsBeforeAfter(elementKey, formData[elementKey], reqData[0][elementKey]));
								}
							});
							// const originalNewInputContainer = jQuery("<div></div>");
							// originalNewInputContainer.attr('style', 'display: flex;');
							// const inputContainer = self.createInput(key, formData[key], key);
							// const inputOriginalContainer = self.createInput(key+'_original', reqData[0][key], key+'_original');
							// inputContainer[0].style.paddingLeft = "5px";
							// inputOriginalContainer[0].style.paddingLeft = "5px";
							// inputContainer[0].style.flex = "1";
							// inputOriginalContainer[0].style.flex = "1";
							// originalNewInputContainer.append(inputOriginalContainer);
							// originalNewInputContainer.append(inputContainer);
							// formDataInputsContainer.append(self.createInputsBeforeAfter(key, reqData[0][key], formData[key]));
						}
					}
				});
				form.append(formDataInputsContainer);
			} else {
				// delete_record
			}

			// Returns the form
			return form;
		},

		renderImgs: function (files){
				var self = this;
				// Creates the div
				var containerDiv = jQuery('<div></div>');
				containerDiv.attr('style', 'display: flex;');
				const label = jQuery('<p>nova imagem</p>');
				const originalLabel = jQuery('<p>imagem_original</p>');
	
				var originalImages = jQuery('<div></div>');
				var requestImages = jQuery('<div></div>');
	
	
				originalImages.append(originalLabel);
				originalImages.attr('style', 'max-width: 50%; border-color: #e0e0e5; border-width: 1px; border-radius: 10px; border-style: solid; margin: 4px; padding: 8px;');
				var link = jQuery('<p style="overflow-wrap: break-word;"><img src="'+this.options.root_url+files['last']['path']+files['last']['name']+'" width="500" height="600"></p>');
				originalImages.append(link);
				requestImages.append(label);
				requestImages.attr('style', 'max-width: 50%; border-color: #e0e0e5; border-width: 1px; border-radius: 10px; border-style: solid; margin: 4px; padding: 8px;');
				var link = jQuery('<p style="overflow-wrap: break-word;"><img src="'+this.options.root_url+files['new']['path']+files['new']['name']+'" width="500" height="600"></p>');
				requestImages.append(link);
				containerDiv.append(originalImages);
				containerDiv.append(requestImages);
				return containerDiv;
		},

		getDatabaseJoinSingleElements: function (join_db_name, original_element_id,
			element_id, join_val_column, join_key_column) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'join_db_name': join_db_name,
					'element_id': element_id,
					'join_val_column': join_val_column,
					'join_key_column': join_key_column,
					'original_element_id': original_element_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetDatabaseJoinSingleData',
					'g': 'form',
				},
			});
		},

		getDatabaseJoinMultipleElements: function (join_db_name, parent_table_name,
			element_name, parent_id, join_val_column,
			join_key_column, request_elements_array) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'request_elements_array': request_elements_array,
					'join_val_column': join_val_column,
					'join_key_column': join_key_column,
					'join_db_name': join_db_name,
					'parent_table_name': parent_table_name,
					'element_name': element_name,
					'parent_id': parent_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetDatabaseJoinMultipleData',
					'g': 'form',
				},
			});
		},


		getFileUploadOriginals: function (parent_table_name, element_name, parent_id) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'parent_table_name': parent_table_name,
					'element_name': element_name,
					'parent_id': parent_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetFileUpload',
					'g': 'form',
				},
			});
		},

		getElementsType: function (req_list_id) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'req_list_id': req_list_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetElementsPlugin',
					'g': 'form',
				},
			});
		},

		getRequest: function (req_id) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'req_id': req_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetRequest',
					'g': 'form',
				}
			});
		},

		getRecord: function (req_list_id, req_record_id) {
			return jQuery.ajax({
				url: this.options.root_url + 'plugins/fabrik_form/workflow/getRecord.php',
				data: {
					'req_list_id': req_list_id,
					'req_record_id': req_record_id
				}
			});
		},

		getElementsPlugin: function (req_list_id) {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'req_list_id': req_list_id,
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetElementsPlugin',
					'g': 'form',
				}
			});
		},

		getRequestType: function (req_request_type_id) {
			return jQuery.ajax({
				url: this.options.root_url + 'plugins/fabrik_form/workflow/getRequestType.php',
				data: { 'req_request_type_id': req_request_type_id }
			});
		},

		getSessionToken: function () {
			return jQuery.ajax({
				'url': '',
				'method': 'get',
				'data': {
					'option': 'com_fabrik',
					'format': 'raw',
					'task': 'plugin.pluginAjax',
					'plugin': 'workflow',
					'method': 'GetSessionToken',
					'g': 'form',
				}
			});
		},

	});
	return FabrikWorkflow;
});