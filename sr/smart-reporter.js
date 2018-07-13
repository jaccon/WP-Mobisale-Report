// Floating notification start
Ext.notification = function(){
	var msgCt;
	function createBox(t, s){
		return ['<div class="msg">',
		'<div class="x-box-tl"><div class="x-box-tr"><div class="x-box-tc"></div></div></div>',
		'<div class="x-box-ml"><div class="x-box-mr"><div class="x-box-mc"><h3>', t, '</h3>', s, '</div></div></div>',
		'<div class="x-box-bl"><div class="x-box-br"><div class="x-box-bc"></div></div></div>',
		'</div>'].join('');
	}
	return {
		msg : function(title, format){
			if(!msgCt){
				msgCt = Ext.core.DomHelper.insertFirst(document.body, {id:'msg-div'}, true);
			}
			msgCt.alignTo(document, 't-t');
			msgCt.applyStyles('left: 33%; top: 30px;');
			var s = Ext.String.format.apply(String, Array.prototype.slice.call(arguments, 1));
			var m = Ext.core.DomHelper.append(msgCt, {html:createBox(title, s)}, true);
			m.slideIn('t').pause(1000).ghost("t", {remove:true});
		},

		init : function(){
			var lb = Ext.get('lib-bar');
			if(lb){
				lb.show();
			}
		}
	};
}();
// Floating notification end

Ext.onReady(function() {
	
	SR                       =  new Object;

	SR.searchTextField 	  = '';
	var monthTitle		  = new Array( "January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December" );
	var salesDetailsDataObject = {};
	var ydata			  = new Array();
	var now 		      = new Date();
	var lastMonDate       = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate() + 1);
	var search_timeout_id = 0; 			//timeout for sending request while searching.
	var dateFormat        = 'M d Y';
	var ordersDetailsDataObject = {};
	var from, to, percent_sales_contribution;
	
	SR.searchTextField = new Ext.form.field.Text({
		id: 'tf',
		width: 250,
		cls: 'searchPanel',
		style: {
			fontSize: '14px',
			paddingLeft: '2px',
			width: '100%'
		},
		params: {
			cmd: 'searchText'
		},
		emptyText: 'Search...',
		enableKeyEvents: true,
		listeners: {
			keyup: function () {
				// make server request after some time - let people finish typing their keyword
				clearTimeout(search_timeout_id);
				search_timeout_id = setTimeout(function () {					
					loadGridStore();
				}, 1000);
			}}
	});
	
	SR.fromDateField = new Ext.form.field.Date({
		fieldLabel: 'From',
		labelWidth : 35,
		emptyText : 'From Date',
		format: dateFormat,
		width: 150,
		editable: false,
		maxValue: now,
		value: new Date(now.getFullYear(), now.getMonth(), 1),
		listeners: {
			select: function ( t, value ){
				smartDateComboBox.reset();
				t.setValue(value);
				loadGridStore();
			}
		}
	});
	
	SR.toDateField = new Ext.form.field.Date({
		fieldLabel: 'To',
		labelWidth : 20,
		emptyText : 'To Date',
		format: dateFormat,
		width: 150,
		editable: false,
		maxValue: now,
		value: now,
		listeners: {
			select: function ( t, value ){
				smartDateComboBox.reset();
				t.setValue(value);
				loadGridStore();
			}
		}
	});
	
	// to limit Lite version to available days
	SR.checkFromDate = new Date(now.getFullYear(), now.getMonth(), ( now.getDate() - 29 ));
	SR.checkToDate   = now;

	var smartDateComboBox = Ext.create('Ext.form.ComboBox', {
		queryMode: 'local',
		width : 100,
		store: new Ext.data.ArrayStore({
			autoDestroy: true,
			forceSelection: true,
			fields: ['value', 'name'],
			data: [
					['TODAY',      'Today'],
					['YESTERDAY',  'Yesterday'],
					['CURRENT_WEEK',  'Current Week'],
					['LAST_WEEK',  'Last Week'],
					['CURRENT_MONTH', 'Current Month'],
					['LAST_MONTH', 'Last Month'],
					['3_MONTHS',   '3 Months'],
					['6_MONTHS',   '6 Months'],
					['CURRENT_YEAR',  'Current Year'],
					['LAST_YEAR',  'Last Year']
				]
		}),
		displayField: 'name',
		valueField: 'value',
		triggerAction: 'all',
		editable: false,
		emptyText : 'Select Date',
		style: {
			fontSize: '14px',
			paddingLeft: '2px'
		},
		forceSelection: true,
		listeners: {
			select: function () {
				var dateValue = this.value;
				if(fileExists == 0){
					if(smartDateComboBox.getValue() == 'TODAY' || smartDateComboBox.getValue() == 'YESTERDAY' || smartDateComboBox.getValue() == 'CURRENT_WEEK' || smartDateComboBox.getValue() == 'LAST_WEEK' || smartDateComboBox.getValue() == 'CURRENT_MONTH'){
						liteSelectDate(dateValue);
						loadGridStore();
					}else{
						Ext.notification.msg('Smart Reporter',"Available only in Pro version" );
					}
				}else{
					proSelectDate(dateValue);
					loadGridStore();
				}
				
			}
		}
	});
	
	var liteSelectDate = function (dateValue){
		var fromDate,toDate;

		switch (dateValue){

			case 'TODAY':
			fromDate = now;
			toDate 	 = now;
			break;

			case 'YESTERDAY':
			fromDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
			toDate 	 = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
			break;

			case 'CURRENT_WEEK':
			fromDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() - (now.getDay() - 1));
			toDate 	 = now;
			break;
			
			case 'LAST_WEEK':
			fromDate = new Date(now.getFullYear(), now.getMonth(), (now.getDate() - (now.getDay() - 1) - 7));
                        toDate   = new Date(now.getFullYear(), now.getMonth(), (now.getDate() - (now.getDay() - 1) - 1));
			break;
			
			default:
			fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
			toDate 	 = now;
			break;
		}

		SR.fromDateField.setValue(fromDate);
		SR.toDateField.setValue(toDate);

		SR.fromDate = fromDate;
		SR.toDate 	= toDate;

		return SR;
	};
	
	// store for graph
	var lineGraphStore = Ext.create('Ext.data.Store', {
		id : 'lineGraphStore',
		autoLoad : false,
		fields : [ {
			name : 'period',
			type : 'string'
		}, {
			name : 'sales',
			type : 'float'
		}
		],
		params : {
			security : srNonce,
			fromDate : SR.fromDateField.getValue(),
			toDate : SR.toDateField.getValue(),
			start : 0,
			cmd : 'getData',
			file_nm: jsonFileNm
		},
		proxy : {
			type : 'ajax',
			// url : jsonURL, // url that will load data with respect to start and
			url: (ajaxurl.indexOf('?') !== -1) ? ajaxurl + '&action=sr_get_stats' : ajaxurl + '?action=sr_get_stats',
			reader : {
				type : 'json',
				totalProperty : 'totalCount',
				root : 'items'
			},
			//this will be used in place of BaseParams of extjs 3
			//Extra parameters that will be included on every request which will help us if we use pagination.
			extraParams :{
				searchText: SR.searchTextField.getValue()
			}
		}
	});
	
	// grid store
	var gridStore = Ext.create('Ext.data.Store', {
		id : 'gridStore',
		autoLoad : false,
		fields : [ {
			name : 'id',
			type : 'int'
		}, {
			name : 'products',
			type : 'string'
		}, {
			name : 'category',
			type : 'string'
		}, {
			name : 'sales',
			type : 'float'
		}, {
			name : 'quantity',
			type : 'int'
		}, {
			name : 'image',
			type : 'string'
		}],
		proxy : {
			type : 'ajax',
			// url : jsonURL, // url that will load data with respect to start and
			url: (ajaxurl.indexOf('?') !== -1) ? ajaxurl + '&action=sr_get_stats' : ajaxurl + '?action=sr_get_stats',
			reader : {
				type : 'json',
				totalProperty : 'gridTotalCount',
				root : 'gridItems'
			}
		},
		listeners : {
			load : function() {
				var model = gridPanel.getSelectionModel();
				if (this.getTotalCount() > 0) {
					Ext.getCmp('salesDetailsPanel').show();
					Ext.getCmp('ordersDetailsPanel').show();
					Ext.getCmp('barchart').show();
					model.select(0);
				} else {
					Ext.getCmp('salesDetailsPanel').hide();
					Ext.getCmp('ordersDetailsPanel').hide();
					Ext.getCmp('barchart').hide();
					Ext.notification.msg('Info','No sales found');
				}
			}
		}
	});
	
	var loadGridStore = function() {
		gridStore.load({
			params : {
				security : srNonce,
				fromDate : SR.fromDateField.getValue(),
				toDate : SR.toDateField.getValue(),
				start : 0,
				searchText: SR.searchTextField.getValue(),
				cmd : 'gridGetData',
				file_nm: jsonFileNm
			}
		});
	};

	var getRawData = function ( records ) {
		from = SR.fromDateField.getValue();
		to = SR.toDateField.getValue();
		var diff_days = ((to - from)/86400000) + 1;
		var image = records[0].data.image;
		var product_name = records[0].data.products;
		var sales = Ext.util.Format.round((records[0].data.sales),2);
		var quantity = records[0].data.quantity;
		var selectedId = records[0].data.id;
		var total = records[0].store.data.items[0].data.sales;									//totalSales + totalDiscount;
		percent_sales_contribution = Ext.util.Format.round((sales/total) *100,2);
		var sales_per_day = Ext.util.Format.round((sales/diff_days),2);
		var velocity = Ext.util.Format.round((diff_days/quantity),4);
		var duration = getDuration(velocity);
		salesDetailsDataObject = {
			product_name : product_name,
			image : image,
			sales : Ext.util.Format.number( sales, '0.00' ),
			quantity : quantity,
			percent_sales_contribution : percent_sales_contribution,
			sales_per_day : Ext.util.Format.number( sales_per_day, '0.00' ),
			duration : duration,
			currency : SR.defaultCurrencySymbol
		};
	};
	
	var loadGraphSalesOrdersDetails = function ( myJsonObj ) {
		ordersDetailsDataObject = myJsonObj.orderDetails.order;
		salesDetails.overwrite( Ext.getCmp('salesDetailsPanel').body, salesDetailsDataObject );
		ordersDetails.overwrite( Ext.getCmp('ordersDetailsPanel').body, ordersDetailsDataObject );
		lineGraphStore.loadData( myJsonObj.graph.items );	
	};
	
	// create a grid that will list the dataset items.
	var gridPanel = Ext.create('Ext.grid.Panel', {
		autoScroll : true,
		columnLines : true,
		flex : 2,
		store : gridStore,
		columns : [
		{
			text : 'Products',
			width : 200,
			flex : 1.5,
			tooltip: 'Product Name',
			sortable : true,
			dataIndex : 'products'
		}, {
			text : 'Category',
			width : 150,
			flex : 1,
			tooltip: 'Category',
			sortable : true,
			dataIndex : 'category'
		},{
			text : 'Sales',
			width : 150,
			flex : 0.5,
			align : 'right',
			tooltip: 'Sales',
			sortable : true,
			xtype : 'numbercolumn',
			format : '0.00',
			dataIndex : 'sales'
		},{
			text : 'Qty',
			width : 150,
			flex : 0.5,
			align : 'right',
			tooltip: 'Quantity',
			sortable : true,
			xtype : 'numbercolumn',
			format : '0',
			dataIndex : 'quantity'
		} ],

		listeners : {
			// Fires when the selected nodes change.
			selectionchange : function(model, records) {
				if (records[0] != undefined) {
					detailsLoadMask.show();
					getRawData( records );
					
					var object = {
						// url : jsonURL,
						url: (ajaxurl.indexOf('?') !== -1) ? ajaxurl + '&action=sr_get_stats' : ajaxurl + '?action=sr_get_stats', 
						method: 'get',
						callback: function (options, success, response) {
							if (true == success) {
								var myJsonObj = Ext.decode(response.responseText);
								loadGraphSalesOrdersDetails( myJsonObj );
							} else {
								Ext.notification.msg('Failed',response.responseText);
								return;
							}
							detailsLoadMask.hide();
						},
						scope: this,
						params : {
							security  : srNonce,
							fromDate  : SR.fromDateField.getValue(),
							toDate    : SR.toDateField.getValue(),
							searchText: SR.searchTextField.getValue(),
							start 	  : 0,
							id 		  : records[0].data.id,
							cmd 	  : 'getData',
							file_nm   : jsonFileNm
						}
					};
					Ext.Ajax.request(object);
				}
			}
		}
	});

	var salesDetails = new Ext.XTemplate(
		'<div id="sales-details-wrapper">',
			'<div id="sales-image-product-name">',
				'<div id="sales-product-name" class="product-name-block">',
					'<div id="product-name"><b>{product_name}</b></div>',
				'</div>',
				'<div id="sales-image"><center>',
					'<img src="{image}" />',
				'</center></div>',
			'</div>',
			'<div id="remaining-sales-detail">',
				'<table width="100%" height="100%">',
					'<tr>',
						'<td><b style="color: #21759B;">{currency}{sales}</b> Sales • <b>{currency}{sales_per_day}</b> per Day</td>',
					'</tr>',
					'<tr>',
						'<td><b style="color: #21759B;">{quantity}</b> units sold • <b style="font-weight: 100;">{percent_sales_contribution}%</b> of total</td>',
					'</tr>',
					'<tr>',
						'<td>1 sale every <b style="font-weight: 100;">{duration}</b></td>',
					'</tr>',
				'</table>',
			'</div>',
		'</div>'
	);

	var ordersDetails = new Ext.XTemplate(
		'<div class="last-few-orders"><table width="100%"><tr><td colspan="3"><h3>Last Few Orders</h3></td></tr>',
		'<tpl for=".">',      
	    '<tr><td><div class="order-date">{date}</div></td><td><div class="order-customer-name"><a href="#" onClick="openWindow({purchaseid});">{cname}</a></div></td><td><div class="price order-total-amount">{totalprice}</div></td></tr>',  // use current array index to autonumber
	    '</tpl>',
	    '</table></div>'
	);

	this.openWindow = function(recordId){
		 Ext.create('Ext.window.Window', {
		    title: 'Order Details',
		    height: 500,
		    width: 500,
		    stateId : 'billingDetailsWindowWpsc',
			stateEvents : ['show','bodyresize','maximize'],
			stateful: true,
			collapsible:true,
			shadow : true,
			shadowOffset: 10,
			minimizable: false,
			maximizable: true,
			maximized: false,
			resizeable: true,
			listeners: {
				maximize: function () {
					this.setPosition( 0, 30, false );
				}
			},
		    html: '<iframe src='+ ordersDetailsLink + '' + recordId +' style="width:100%;height:100%;border:none;"><p>Your browser does not support iframes.</p></iframe>'
		}).show();
	}; 
	
	
	var plural = function ( number ) {
		var str = ( number > 1 ) ? 's' : '';
		return str;
	};
	
	var getDuration = function(result) 
	{
		/**
			 * Result comes on the basis of 1 day. i.e if velocity is 1. it is per day.
			 * 
			 * if velocity is less than 1, say 0.5 than it is per 12 hours.
			 * So, 1 hr = 0.0416 days
			 */
			var duration = '';
			var valueAsPerDuration = 0;
			var remainderValue = 0;
			var value = result;
			if (value < 0.0416)
			{
				valueAsPerDuration=(value / 0.0416) * 60;
				duration=Ext.util.Format.round(valueAsPerDuration,2) + ' minute' + plural( valueAsPerDuration );
			}
			else if (value < 1)
			{
				/**
				 * In this we convert 1 day velocity to be based upon Hours.
				 * So we get say, 0.5 days we multiply it by 24 and it becomes 12hrs.
				 * 
				 * 1mn = 0.0167 hrs.
				 */
				valueAsPerDuration=value * 24;
				remainderValue=Math.floor(((valueAsPerDuration % 1) / 0.0167));
				duration=Math.floor(valueAsPerDuration) + ' hour' + plural( valueAsPerDuration );
				duration+=(remainderValue != 0) ? ' ' + Ext.util.Format.round(remainderValue,0) + ' minute' : '';
				duration+=plural( remainderValue );
			}

			else if (value < 7)
			{
				valueAsPerDuration=value;
				remainderValue= Ext.util.Format.round(((valueAsPerDuration % 1) * 24),0);
				duration=Math.floor(valueAsPerDuration) + ' day' + plural( valueAsPerDuration );
				duration+=(remainderValue != 0) ? ' ' + remainderValue + ' hour' : '';
				duration+=plural( remainderValue );
			}
			else if (value < 30)
			{
				valueAsPerDuration=value / 7;
				remainderValue=Ext.util.Format.round((valueAsPerDuration % 7),0);
				duration=Math.floor(valueAsPerDuration) + ' week' + plural( valueAsPerDuration );
				duration+=(remainderValue != 0) ? ' ' + remainderValue + ' day' : '';
				duration+=plural( remainderValue );
			}
			else if (value < 365)
			{
				valueAsPerDuration=value / 30;
				remainderValue=Ext.util.Format.round((valueAsPerDuration % 30),0);
				duration=Math.floor(valueAsPerDuration) + ' month' + plural( valueAsPerDuration );
				duration+=(remainderValue != 0) ? ' ' + remainderValue + ' day' : '';
				duration+=plural( remainderValue );
			}
			else if (value > 365)
			{
				valueAsPerDuration=value / 365;
				remainderValue=Ext.util.Format.round((valueAsPerDuration % 365),0);
				var additionalText ='';
		
				if (remainderValue > 30)
				{
					remainderValue=Ext.util.Format.round((remainderValue / 30),0);
					additionalText=' month' + plural( remainderValue );
				}
				else
				{
					additionalText=' day' + plural( remainderValue );
				}
				duration=Math.floor(valueAsPerDuration) + ' year' + plural( valueAsPerDuration );
				duration+=(remainderValue != 0) ? ' ' + remainderValue + additionalText : '';
			}
			else
			{
				duration='??';
			}
		
		return(duration);
		
	};
	
	// create a bar series to be at the top of the panel.
	var barChart = Ext.create('Ext.chart.Chart', {  //Use redraw() for Reloading.
		id : 'barchart',
		flex : 1,
		margin : '10 5 0 0',
		cls: 'bar-chart',
		height : 300,
		width: 150,
		insetPadding: 10,
		shadow : false,
		animate : true,
		resize : false,
		store : lineGraphStore,
		params : {
			fromDate : SR.fromDateField.getValue(),
			toDate : SR.toDateField.getValue(),
			start : 0,
			cmd : 'getData'
		},
		axes : [{
			type : 'Numeric',
			position : 'left',
			fields : [ 'sales' ],
			label : {
				font : '17px Lucida Grande'
			},
			title: 'Sales',
			minimum : 0
		}, {
			type : 'Category',
			position : 'bottom',
			label : {
				font : '17px Lucida Grande'
			},
			title: 'Timeline',
			fields : [ 'period' ]
		} ],
		series : [ {
			type : 'line',
			smooth : true,
			highlight: {
                size: 7,
                radius: 7
            },
			axis : 'left',
			highlight : true,
			style : {
				fill : '#456d9f'
			},
			highlightCfg : {
				fill : '#000'
			},
			markerConfig : {
				color : '#D7E3F2',
				type : 'circle',
				size : 4,
				radius : 2
			},
			tips : {
				trackMouse : true,
				width : 125,
				constrainPosition: true,
				renderer : function(storeItem, item) {
					var period = '';
					var periodValue = storeItem.data['period'];
					var fromDate = new Date( SR.fromDateField.getValue() );
					var monthIndex = fromDate.getMonth();
					var year = fromDate.getFullYear();
					switch ( periodValue.length ) {
						case 1:
						case 2:
                                                        //If statement to handle the display of the time when Today is selected
                                                        if (storeItem.data['time'] != "" && storeItem.data['time'] != null) {
                                                            if (storeItem.data['sales'] > 0) {
                                                                period = 'Last Order: ' + storeItem.data['time'];
                                                            }
                                                            else {
                                                                period = 'Last Order: -';
                                                            }
                                                        }
                                                        else {
							period = monthTitle[monthIndex] + ' ' + periodValue + ', ' + year;
                                                        }
							break;
						case 3:
							period = periodValue + ' ' + year;
							break;
						case 4:
							period = periodValue;
							break;
					}
					
					var toolTipText = '';
						toolTipText = 'Sales: '+SR.defaultCurrencySymbol + storeItem.data['sales'] + '<br\> ' + period;
					this.setTitle(toolTipText);
				}
			},
			listeners : {
				'itemmouseup' : function(item) {
					// code to select the grid data on click of the graph.
				}
			},
			xField : [ 'period' ],
			yField : [ 'sales' ]
		} ]
	});
	// disable highlighting by default.
	barChart.series.get(0).highlight = true;
	
	var gridForm = Ext.create('Ext.form.Panel', {
		tbar : [ '<b>Sales</b>',
		{ xtype : 'tbspacer' },
		SR.fromDateField,
		{ xtype : 'tbspacer'},
		SR.toDateField,
		{ xtype : 'tbspacer'},

		smartDateComboBox, '',SR.searchTextField,{ icon: imgURL + 'search.png' },
		'->', {
			text : '',
			icon : imgURL + 'refresh.gif',
			tooltip : 'Reload',
			scope : this,
			id : 'reload',
			listeners : {
				click : function() {
                                                loadGridStore();
                                                if ( fileExists == 1 ) {
                                                    jQuery('#wrap_sr_kpi').fadeTo('fast', 0.5);
                                                    jQuery.ajax({
                                                        // url: fileUrl,
                                                        url: (ajaxurl.indexOf('?') !== -1) ? ajaxurl + '&action=sr_get_stats' : ajaxurl + '?action=sr_get_stats',
                                                        dataType: 'html',
                                                        success: function( response ){
                                                            jQuery('#wrap_sr_kpi').html(response).fadeTo('fast', 1);
                                                        }
                                                    });
                                                }
					}
				}
			}],
	
		height : 400,
		layout : {
				type : 'hbox',
				align : 'stretch'
			},

		items : [ gridPanel,
		 {
			layout : {	
				type : 'vbox',
				align : 'stretch'
			},
			id : 'details',
			flex : 3,
			border : false,
			items : [{
			        	flex:0.6,
			        	border: false,
						layout : {   
							type : 'hbox',
							align : 'stretch'
						},
						items : [ 
						   {   
							   id : 'salesDetailsPanel',
							   flex : 0.55,
							   border: false,
							   items : [ salesDetails ]  
						   },
						   {
							   id : 'ordersDetailsPanel',
							   flex : 0.45,							   
							   border: false,
							   items : [ ordersDetails ]
						   }]
			          	},
			            barChart ]
		 		 }],  
			listeners : {
		 		afterrender : function() {
		 			loadGridStore();
		 		}
		 	},
			renderTo : 'smart-reporter'
		  });
	
	var detailsLoadMask = new Ext.LoadMask(Ext.getCmp('details').el, {msg:"Loading..."});
		  
	smartDateComboBox.setValue(selectedDateValue);
	if(fileExists == 0){
		SR.fromDateField.setValue(SR.checkFromDate);
		SR.fromDateField.setMinValue(SR.checkFromDate);
		SR.toDateField.setMinValue(SR.checkFromDate);
	}else{
		proSelectDate(selectedDateValue);
	}

});