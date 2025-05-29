import { __ } from "@wordpress/i18n";
import Button from "../Common/Button";
import Select from "../Common/Select";
import SbUtils from "../../Utils/SbUtils";
import { useContext, useEffect, useState } from "react";

const FormsSubmissionModal = (props) => {
	const currentContext = SbUtils.getCurrentContext();
	const {
		sbCustomizer,
		forms,
		subFrModal,
		editorTopLoader,
		curCl,
		currentForms,
		editorNotification,
		formSubScreen,
		collectionRv,
		currentCollPageRef
    } = useContext( currentContext );

	const installedFormPlugins = forms?.formsManagerData.filter(form => form?.info?.is_installed === true && form?.info?.is_active === true)

	const setCreateFormUrl = (form = 'wpforms') => {
		let url = new URL(sbCustomizer?.adminHomeURL);
		switch (form) {
			case 'wpforms':
				url.searchParams.append('page', 'wpforms-builder');
				break;
			case 'formidable':
				url.searchParams.append('page', 'formidable-form-templates');
				url.searchParams.append('return_page', 'forms');
				break;
				default:
					break;
			}
		url.searchParams.append('sbrfeeds', 'reviews');
		return url;
	}

	const [ formToConnect, setFormToConnect ] = useState(false)

	const connectNewForm = () => {
		if (SbUtils.jsonParse(formToConnect) !== false) {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;

			const formData = {
				action : 'sbr_feed_connect_new_form',
				collectionID: curCl?.currentCollection?.id,
				collectionAccountID: curCl?.currentCollection?.account_id,
				formInfo: formToConnect,
				submissionPage 			: page,
				collectionPageNumber 	: currentCollPageRef.current,
				archivedPage 			: currentArchivedPage
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : __( 'New Form connect successfully!', 'reviews-feed' )
				}
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
						subFrModal?.setFormSubmissionModalActive(false)
					}
					setTimeout(() => {
						SbUtils.applyNotification( {
							icon : 'success',
							text : __( 'We are importing your form submissions', 'reviews-feed' ),
							time : 5000
						} , editorNotification )
					}, 3500);
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}
	}

	const initSelectedForm = () => {
		if (installedFormPlugins.length === 1) {
			if (undefined !== installedFormPlugins[0]?.formsList
				&& installedFormPlugins[0]?.formsList.length === 1
			) {
				setFormToConnect(
					JSON.stringify(installedFormPlugins[0]?.formsList[0])
				)
			}
		}
	}

	const [defaultModalPlugin, setDefaultModalPlugin] = useState( 'wpforms' );

	const installPluginModalInfo = {
		wpforms : {
			heading : __('Install WPForms', 'reviews-feed'),
			text : __('Looks like you do not have any compatible form plugin installed. Purchase WPForms and start setting up your form right away.', 'reviews-feed'),
			link : 'https://wpforms.com/?utm_source=smash-balloon&utm_medium=reviews-feed-pro&utm_campaign=install&utm_content=PurchaseWPFormsPro',
			buttonText : __( 'Purchase WPForms Pro', 'sb-customizer' )
		},
		formidable : {
			heading : __('Install Formidable Pro', 'reviews-feed'),
			text : __('Looks like you do not have any compatible form plugin installed. Purchase Formidable forms Pro and start setting up your form right away.', 'reviews-feed'),
			link : 'https://formidableforms.com/?utm_source=smash-balloon&utm_medium=reviews-feed-pro&utm_campaign=install&utm_content=PurchaseFormidableFormsPro',
			buttonText : __( 'Purchase Formidable Forms Pro', 'sb-customizer' )
		},
		edd : {
			heading : __('Install Easy Digital Downloads', 'reviews-feed'),
			text : __('Looks like you do not have any compatible form plugin installed. Purchase Easy Digital Downloads and start setting up your form right away.', 'reviews-feed'),
			link : 'https://easydigitaldownloads.com/?utm_source=smash-balloon&utm_medium=reviews-feed-pro&utm_campaign=install&utm_content=PurchaseEasyDigitalDownloads',
			buttonText : __( 'Purchase Easy Digital Downloads', 'sb-customizer' )
		}
	}

	const checkDefaultModalPlugin = () => {
		let defaultPlugin = 'wpforms';
		const formPlugins = SbUtils.checkInstalledForms(forms.formsManagerData);
		console.log(formPlugins)

		if (SbUtils.checkSingleForm('wpforms', formPlugins) === false &&
			SbUtils.checkSingleForm('formidable', formPlugins)  === true
		) {
			defaultPlugin = 'formidable';
		}
		setDefaultModalPlugin(defaultPlugin)
	}

	useEffect(() => {
		initSelectedForm();
		checkDefaultModalPlugin();
    }, []);


	const openCreateFromTemplate = () => {
		if (undefined !== installedFormPlugins[0]?.info?.id) {
			window.open(setCreateFormUrl(installedFormPlugins[0].info.id),'_blank')
		}
	}



	return (
		<div className='sb-feedsources-modal sb-fs'>
			{
				installedFormPlugins.length === 0 &&
				<div className='sb-feedsources-modal-content'>
					<div className='sb-form-subm-half-install sb-fs'>
						<div className='sb-form-subm-half-row sb-fs'>
							<div className='sb-form-subm-half-row-icon'>
								{
									SbUtils.printIcon(defaultModalPlugin, false, false, 36)
								}
							</div>
							<div className='sb-form-subm-half-row-text'>
								<strong>
									{
										installPluginModalInfo[defaultModalPlugin].heading
									}
								</strong>
								<span>
									{
										installPluginModalInfo[defaultModalPlugin].text
									}
								</span>
							</div>
						</div>
					</div>
					<div className='sb-feedsources-modal-btns sb-fs'>
						<Button
							size='medium'
							type='secondary'
							onClick={ () => {
								subFrModal?.setFormSubmissionModalActive(false)
							}}
							text={ __( 'Cancel', 'sb-customizer' ) }
						/>
						<Button
							icon='success'
							iconSize='12'
							size='medium'
							type='primary'
							link={installPluginModalInfo[defaultModalPlugin].link}
							target="_blank"
							text={installPluginModalInfo[defaultModalPlugin].buttonText}
						/>
					</div>
				</div>
			}
			{
				installedFormPlugins.length > 0 &&
				<div className='sb-feedsources-modal-content'>
					<div className='sb-feedsources-modal-heading sb-subform-modal-heading sb-fs'>
						<h4 className='sb-h4'>{ __( 'Connect a form', 'sb-customizer' ) }</h4>
					</div>
					<div className='sb-form-subm-connect-inps sb-fs'>
						<div className='sb-fs'>
							<Select
								size='medium'
								label={ __( 'Form', 'reviews-feed') }
								value={formToConnect}
								onChange={(event) => {
									if (SbUtils.jsonParse(event.target.value) !== false) {
										setFormToConnect(event.target.value)
									}
								}}
							>
								<option
									value={false}
								>
									{ __( 'Select', 'reviews-feed') }
								</option>
								{
									installedFormPlugins.map((formProvider, formProviderInd) => {
										return (
											<optgroup
												label={formProvider?.info?.name}
												key={formProviderInd}
											>
												{
													formProvider?.formsList.map((form, formInd) => {
														return (
															<option
																key={formInd}
																value={JSON.stringify(form)}
															>
																{form?.name}
															</option>
														)
													})
												}
											</optgroup>
										)
									} )
								}
							</Select>
						</div>
						<div className='sb-form-subm-half-row sb-fs'>
							<div className='sb-form-subm-half-row-text'>
								<strong>
									{SbUtils.printIcon('help', false, false, 18)}
									{__('Cannot find a form?', 'reviews-feed')}
								</strong>
								<span>
									{__('To select a form, you need to first create a compatible form from templates', 'reviews-feed')}
								</span>
							</div>
							<div className='sb-form-subm-half-row-btns'>
								<Button
									icon='chevron-right'
									icon-position='right'
									iconSize={8}
									size='medium'
									type='secondary'
									onClick={ () => {
										openCreateFromTemplate()
									}}
									text={ __( 'Create from Template', 'sb-customizer' ) }
								/>
							</div>
						</div>
					</div>
					<div className='sb-feedsources-modal-btns sb-fs'>
						<Button
							size='medium'
							type='secondary'
							onClick={ () => {
								subFrModal?.setFormSubmissionModalActive(false)
							}}
							text={ __( 'Cancel', 'sb-customizer' ) }
						/>
						<Button
							icon='success'
							iconSize='12'
							size='medium'
							type='primary'
							onClick={ () => {
								connectNewForm()
							} }
							text={ __( 'Connect', 'sb-customizer' ) }
						/>
					</div>
				</div>
			}
		</div>
	)
}

export default FormsSubmissionModal;