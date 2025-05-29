import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";
import SbUtils from "../../../../../Utils/SbUtils";
import Select from "../../../../Common/Select";
import Input from "../../../../Common/Input";
import { useContext, useEffect, useState } from "react";

const SubmissionsSettings = (props) => {

	const currentContext = SbUtils.getCurrentContext();
	const {
		sbCustomizer,
		curCl,
		currentForms,
		editorTopLoader,
		editorNotification,
		subFrModal,
		editorConfirmDialog,
		formSubScreen,
		currentCollPageRef,
		collectionRv
	} = useContext( currentContext );

	const deleteConnectedForm = (formToDisconnect) => {
		const confirmDialogInfo = {
				active : true,
				heading : `${ __('Disconnect ', 'reviews-feed') + '"'+ formToDisconnect?.name +'"?'  }`,
				description : __( 'Are you sure you want to disconnect this form?', 'reviews-feed' ),
				confirm : {}
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __( 'Form Disconnected!', 'reviews-feed' )
			}
		};

		confirmDialogInfo.confirm.onConfirm = () => {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;
			const formData = {
				action 					: 'sbr_feed_disconnect_form',
				collectionAccountID 	: curCl.currentCollection.account_id,
				formInfo				: JSON.stringify(formToDisconnect),
				submissionPage 			: page,
				collectionPageNumber 	: currentCollPageRef.current,
				archivedPage 			: currentArchivedPage
			}
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					props?.setAjaxResponseData(
						data?.submissionsList,
						page,
						data?.archivedList,
						currentArchivedPage,
						data?.submissionsCount,
						data?.archivedCount
					)
					if (data?.postsList) {
						let parsedPostList = data?.postsList.map(element => {
							element = JSON.parse(element.json_data)
							return element;
						});
						collectionRv.setCollectionReviewsList(parsedPostList);
					}
					if (data?.currentCollection) {
						curCl.setCurrentCollection(data?.currentCollection)
						const connectedForms = SbUtils.getCollectionConnectedForms(data?.currentCollection);
						currentForms.setCurrentConnectedForms(connectedForms)
						formSubScreen?.setCurrentView(connectedForms.length > 0 ? 'settings' : 'empty')
					}
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}
		editorConfirmDialog.setConfirmDialog( confirmDialogInfo )
	}

	const ratingRulesOptions = [
		{
			value : '',
			label : __('Select a rule','reviews-feed')
		},
		{
			value : 3,
			label : __('has 3 stars or more','reviews-feed')
		},
		{
			value : 4,
			label : __('has 4 stars or more','reviews-feed')
		},
		{
			value : 5,
			label : __('has 5 stars or more','reviews-feed')
		},
	];

	const charactersRulesOptions = [
		{
			value : '',
			label : __('Select a rule','reviews-feed')
		},
		{
			value : 'longer',
			label : __('is longer than','reviews-feed')
		},
		{
			value : 'shorter',
			label : __('is shorter than','reviews-feed')
		}
	];

	const ratingArchiveRulesOptions = [
		{
			value : '',
			label : __('Select a rule','reviews-feed')
		},
		{
			value : 1,
			label : __('has 1 star or less','reviews-feed')
		},
		{
			value : 2,
			label : __('has 2 stars or less','reviews-feed')
		},
		{
			value : 3,
			label : __('has 3 stars or less','reviews-feed')
		},
		{
			value : 4,
			label : __('has 4 stars or less','reviews-feed')
		},
		{
			value : 5,
			label : __('has 5 stars or less','reviews-feed')
		},
	];

	// Rules (Auto-Approve + Auto-Archive)
	const defaultRules = {
		autoApprove : {
			rating : '',
			characters : {
				type : '',
				number : ''
			}
		},
		autoArchive : {
			rating : '',
			characters : {
				type : '',
				number : ''
			}
		}
	}
	const extractRules = (collection) => {
		const info = (typeof collection?.info === 'object') ? collection?.info : SbUtils.jsonParse(collection?.info);
		return info === false || info?.form_rules === undefined ? defaultRules : info?.form_rules;
	}
	const [ collectionRules, setCollectionRules ] = useState( extractRules(curCl.currentCollection) );



	const applyCollectionRules = () => {
		const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
		const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;

		const formData = {
			action 					: 'sbr_feed_update_form_rules',
			collectionAccountID 	: curCl.currentCollection.account_id,
			formRules				: JSON.stringify(collectionRules),
			submissionPage 			: page,
           	collectionPageNumber 	: currentCollPageRef.current,
			archivedPage 			: currentArchivedPage
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __( 'Collection form rules updated!', 'reviews-feed' )
			}
		};

		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => { //Call Back Function
				props?.setAjaxResponseData(
					data?.submissionsList,
					page,
					data?.archivedList,
					currentArchivedPage,
					data?.submissionsCount,
					data?.archivedCount
				)
				if (data?.postsList) {
					let parsedPostList = data?.postsList.map(element => {
						element = JSON.parse(element.json_data)
						return element;
					});
					collectionRv.setCollectionReviewsList(parsedPostList);
				}

				if (data?.currentCollection) {
					curCl.setCurrentCollection(data?.currentCollection)
					setCollectionRules(data?.currentCollection?.info?.form_rules )
				}
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
	}

	return (
		<section className='sb-submform-settings-ctn sb-fs'>
			<div className='sb-submformset-forms-section sb-fs'>
				<div className='sb-submformset-top sb-fs'>
					<div className='sb-submformset-top-txt'>
						<strong>{ __( 'Connected Forms', 'sb-customizer' ) }</strong>
						<span>{ __( 'Configure forms that are allowed to add reviews to this collection.', 'sb-customizer' ) }</span>
					</div>
					<div className='sb-submformset-top-act'>
						<Button
							icon='plus'
							size='small'
							type='primary'
							onClick={ () => {
								subFrModal?.setFormSubmissionModalActive(true)
							}}
							text={ __( 'Add Form', 'sb-customizer' ) }
						/>
					</div>
				</div>

				<table className='sb-submformset-forms-list'>
					<tbody>
						{
							currentForms?.currentConnectedForms.length > 0 &&
							currentForms?.currentConnectedForms.map((sForm, sFormIn) => {
								return (
									<tr
										key={sFormIn}
									>
										<td className='sb-submformset-form-name'>
											<div>
												{SbUtils.printIcon('form-item', false, false, 15)}
												<strong>{sForm?.name}</strong>
											</div>
										</td>
										<td className='sb-submformset-form-type'>
											{sForm?.pluginName || sForm?.plugin}
										</td>
										<td className='sb-submformset-form-actions'>
											<div className='sb-feed-item-btns'>
												<div className='sb-relative'>
													<Button
														type='secondary'
														size='small'
														iconSize='14'
														boxshadow={false}
														icon='pen'
														tooltip={ __( 'Edit', 'reviews-feed' ) }
														onClick={ () => {
														} }
														customClass='sb-feed-action-btn sb-feed-edit-btn'
													/>
												</div>
												<div className='sb-relative'>
													<Button
														type='secondary'
														size='small'
														iconSize='11'
														boxshadow={false}
														icon='trash'
														tooltip={ __( 'Delete', 'reviews-feed' ) }
														onClick={ () => {
															deleteConnectedForm(sForm)
														} }
														customClass='sb-feed-action-btn sb-feed-delete-btn'
													/>
												</div>
											</div>
										</td>
									</tr>
								)
							})
						}
					</tbody>
				</table>


			</div>

			<div className='sb-submformset-forms-section sb-fs'>
				<div className='sb-submformset-top sb-fs'>
					<div className='sb-submformset-top-txt'>
						<strong>{ __( 'Rules', 'sb-customizer' ) }</strong>
						<span>{ __( 'Add rules to auto-approve or auto-delete form submissions', 'sb-customizer' ) }</span>
					</div>
					<div className='sb-submformset-top-act'>
						<Button
							icon='plus'
							size='small'
							type='primary'
							onClick={ () => {
								applyCollectionRules()
							}}
							text={ __( 'Apply rules', 'sb-customizer' ) }
						/>
					</div>
				</div>
				<div className='sb-submformset-rules-ctn sb-fs'>

					<div className='sb-submformset-rules-item sb-fs'>
						<div className='sb-submformset-top sb-fs'>
							<div className='sb-submformset-top-txt'>
								<strong>{ __( 'Auto Approve', 'sb-customizer' ) }</strong>
								<span>{ __( 'Add posts to collection automatically if...', 'sb-customizer' ) }</span>
							</div>
						</div>
						<div className='sb-submformset-rules-item-form sb-svg-i'>
							<div className='sb-submformset-rules-row'>
								<strong>{ __( 'REVIEW', 'sb-customizer' ) }</strong>
								<div className='sb-submformset-rule-inps'>
									<Select
										size='medium'
										value={collectionRules?.autoApprove?.rating}
										onChange={(event) => {
											setCollectionRules({
												...collectionRules,
												autoApprove : {
													...collectionRules.autoApprove,
													rating: parseInt(event.target.value)
												}
											})
										}}
									>
										{
											ratingRulesOptions.map(pr=> {
												return (
													<option key={pr?.value} value={pr?.value}>{pr?.label}</option>
												)
											})
										}
									</Select>
								</div>
								<div
									className='sb-submformset-rule-delete'
									onClick={() => {
										setCollectionRules({
											...collectionRules,
											autoApprove : {
												...collectionRules.autoApprove,
												rating: ''
											}
										})
									}}
								>
									{SbUtils.printIcon('trash', false, false, 13)}
								</div>
							</div>
							<div className='sb-submformset-rules-row'>
								<strong>{ __( 'AND', 'sb-customizer' ) }</strong>
								<div className='sb-submformset-rule-inps'>
									<Select
										size='medium'
										value={collectionRules?.autoApprove?.characters?.type}
										onChange={(event) => {
											setCollectionRules({
												...collectionRules,
												autoApprove : {
													...collectionRules.autoApprove,
													characters : {
														...collectionRules.autoApprove.characters,
														type : event.target.value
													}
												}
											})
										}}
									>
										{
											charactersRulesOptions.map(pr=> {
												return (
													<option key={pr?.value} value={pr?.value}>{pr?.label}</option>
												)
											})
										}
									</Select>
									<Input
										size='medium'
										placeholder={ __( '200', 'sb-customizer' ) }
										trailingText={ __( 'chars', 'sb-customizer' ) }
										value={collectionRules?.autoApprove?.characters?.number}
										onChange = { ( event ) => {
											setCollectionRules({
												...collectionRules,
												autoApprove: {
													...collectionRules.autoApprove,
													characters : {
														...collectionRules.autoApprove.characters,
														number : parseInt(event.target.value)
													}
												}
											})
										}}
									/>
								</div>
								<div
									className='sb-submformset-rule-delete'
									onClick={(event) => {
										setCollectionRules({
											...collectionRules,
											autoApprove : {
												...collectionRules.autoApprove,
												characters : {
													number : '',
													type : ''
												}
											}
										})
									}}
								>
									{SbUtils.printIcon('trash', false, false, 13)}
								</div>
							</div>
						</div>
					</div>

					<div className='sb-submformset-rules-item sb-fs'>
						<div className='sb-submformset-top sb-fs'>
							<div className='sb-submformset-top-txt'>
								<strong>{ __( 'Auto Archive', 'sb-customizer' ) }</strong>
								<span>{ __( 'Archive form submission automatically if...', 'sb-customizer' ) }</span>
							</div>
						</div>
						<div className='sb-submformset-rules-item-form sb-svg-i'>
							<div className='sb-submformset-rules-row'>
								<strong>{ __( 'REVIEW', 'sb-customizer' ) }</strong>
								<div className='sb-submformset-rule-inps'>
									<Select
										size='medium'
										value={collectionRules?.autoArchive?.rating}
										onChange={(event) => {
											setCollectionRules({
												...collectionRules,
												autoArchive : {
													...collectionRules.autoArchive,
													rating: parseInt(event.target.value)
												}
											})
										}}
									>
										{
											ratingArchiveRulesOptions.map(pr=> {
												return (
													<option key={pr?.value} value={pr?.value}>{pr?.label}</option>
												)
											})
										}
									</Select>
								</div>
								<div
									className='sb-submformset-rule-delete'
									onClick={() => {
										setCollectionRules({
											...collectionRules,
											autoArchive : {
												...collectionRules.autoArchive,
												rating: ''
											}
										})
									}}
								>
									{SbUtils.printIcon('trash', false, false, 13)}
								</div>
							</div>
							<div className='sb-submformset-rules-row'>
								<strong>{ __( 'AND', 'sb-customizer' ) }</strong>
								<div className='sb-submformset-rule-inps'>
									<Select
										size='medium'
										value={collectionRules?.autoArchive?.characters?.type}
										onChange={(event) => {
											setCollectionRules({
												...collectionRules,
												autoArchive: {
													...collectionRules.autoArchive,
													characters : {
														...collectionRules.autoArchive.characters,
														type : event.target.value
													}
												}
											})
										}}
									>
										{
											charactersRulesOptions.map(pr=> {
												return (
													<option key={pr?.value} value={pr?.value}>{pr?.label}</option>
												)
											})
										}
									</Select>
									<Input
										size='medium'
										placeholder={ __( '200', 'sb-customizer' ) }
										trailingText={ __( 'chars', 'sb-customizer' ) }
										value={collectionRules?.autoArchive?.characters?.number}
										onChange = { ( event ) => {
											setCollectionRules({
												...collectionRules,
												autoArchive: {
													...collectionRules.autoArchive,
													characters : {
														...collectionRules.autoArchive.characters,
														number : parseInt(event.target.value)
													}
												}
											})
										}}
									/>
								</div>
								<div
									className='sb-submformset-rule-delete'
									onClick={(event) => {
										setCollectionRules({
											...collectionRules,
											autoArchive : {
												...collectionRules.autoArchive,
												characters : {
													number : '',
													type : ''
												}
											}
										})
									}}
								>
									{SbUtils.printIcon('trash', false, false, 13)}
								</div>
							</div>
						</div>
					</div>


				</div>
			</div>

		</section>
	)
}

export default SubmissionsSettings;