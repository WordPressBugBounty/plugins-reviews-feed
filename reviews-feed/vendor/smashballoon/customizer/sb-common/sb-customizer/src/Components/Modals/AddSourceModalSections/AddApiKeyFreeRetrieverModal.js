import { useContext, useState } from "react";
import SbUtils from "../../../Utils/SbUtils";
import { __ } from "@wordpress/i18n";
import Button from "../../Common/Button";
import Input from "../../Common/Input";

const AddApiKeyFreeRetrieverModal = (props) => {
	const currentContext =  SbUtils.getCurrentContext();
    const {
        apis,
		isPro,
        apiLimits,
		freeRet,
		addSModal,
		freeRetModal,
        sbCustomizer,
		globalNotices,
		editorTopLoader,
        editorNotification,
    } = useContext( currentContext );

	const [ currentProvider, setCurrentProvider ] = useState(null)
	const [ apiResponseType, setApiResponseType ] = useState(null)
	const [ addApiLoading, setAddApiLoading ] = useState(false)

	const [ apiInputValue, setApiInputValue ] = useState({
		google : '',
		yelp : ''
	})

	const apiKeyProviderInfo = [
		{
			provider 	: 'google',
			heading 	: __('Google API key','reviews-feed'),
			footer 		: __('How to get a Goole API key','reviews-feed'),
			link 		: 'https://smashballoon.com/doc/creating-a-google-api-key/'
		},
		{
			provider 	: 'yelp',
			heading 	: __('Yekp API key','reviews-feed'),
			footer 		: __('How to get a Yelp API key','reviews-feed'),
			link 		: 'https://smashballoon.com/doc/creating-a-yelp-api-key/'
		}
	];

	const addApiKeyAction = (provider) => {

		if (SbUtils.checkNotEmpty(apiInputValue[provider])) {
			setCurrentProvider(provider);
			setAddApiLoading(true)
			 const formData = {
				action : 'sbr_feed_saver_manager_update_api_key',
				provider : provider,
				apiKey : apiInputValue[provider],
				removeApiKey : false
			}
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					setAddApiLoading(false)

					if( data?.error !== undefined ){
						const errorUnknown = {
							type : 'error',
							icon : 'notice',
							text : __('Unknown error occured!', 'sb-customizer')
						}
						SbUtils.applyNotification( errorUnknown , editorNotification )
					}
					if (data?.freeRetrieverData) {
						freeRet.setFreeRetrieverData(data?.freeRetrieverData)
					}
					if( data?.error !== undefined ){
						processAPIKeyReturn(data?.error);
					}
					if( data?.apiKeys !== undefined ){
						processAPIKeyReturn(data?.apikey);
						setTimeout(() => {
							apis.setApiKeys( data?.apiKeys );
							freeRetModal.setRetModalType(SbUtils.initRetrieveModalScreen(apis, freeRet))
                        	addSModal.setModalType('addSource')
							props?.onSuccessApiKey(provider)
						}, 4500);
					}

					if( data?.pluginNotices !== undefined ){
						const newPluginNotices = [...data?.pluginNotices]
						globalNotices.setPluginNotices( newPluginNotices );
					}
				},
				editorTopLoader,
				editorNotification,
				null
			)
		}

	}

	const processAPIKeyReturn = ( apiKeyReturn ) => {
        if( apiKeyReturn !== undefined ){
            let notificationApiKey = {};
            if( apiKeyReturn === 'valid' ){
                notificationApiKey = {
                    icon : 'success',
                    text : __('API Key Updated', 'sb-customizer')
                }
				setApiResponseType( "success" );
            }
            else if( apiKeyReturn === 'invalid' || apiKeyReturn === 'invalidKey' ){
				setApiResponseType( "error" );
                notificationApiKey = {
                    type : 'error',
                    icon : 'notice',
                    text : __('Invalid API Key', 'sb-customizer')
                }
            }
            SbUtils.applyNotification( notificationApiKey , editorNotification )
        }

    }

	const shouldShowProvider = (provider) => {
		return !SbUtils.checkNotEmpty(apis?.apiKeys[provider])
	}


	return (
		<div className="sb-modal-message sb-flex sb-flex-center sb-fs" data-style="fixed-elements" data-screen="addapikey">
			<div className="sb-modal-msg-heading sb-flex sb-fs">
				<h4 className="sb-h4">
					{__('Add API Keys','reviews-feed')}
				</h4>
				<span className="sb-text-small sb-dark2-text sb-standard-p">
					{__('Currently the Yelp and Google’s API does not allow us to update your feed without you creating an app and adding an API key.','reviews-feed')}
				</span>
			</div>
			<div className="sb-modal-msg-api-keys sb-flex sb-fs">
				{
					apiKeyProviderInfo.map((pr, pkey) => {
						if (!shouldShowProvider(pr.provider)) {
							return null
						} else {
							return (
								<div
									className="sb-modal-provider-row sb-flex sb-fs"
									key={pkey}
								>
									<div className="sb-modal-provider-ch sb-flex sb-fs">
										{
											SbUtils.printIcon(pr.provider + '-provider' , 'sb-source-svg', false, 18)
										}
										<strong>{pr.heading}</strong>
									</div>
									{
										(
											currentProvider !== pr.provider ||
											(currentProvider === pr.provider && apiResponseType !== 'success')
										) &&
										<div className="sb-modal-provider-add sb-fs  sb-svg-i">
											<div className="sb-modal-provider-add-in sb-flex sb-fs">
												<div className="sb-modal-provider-input sb-flex sb-fs"
													data-error={currentProvider === pr.provider && apiResponseType === 'error'}
												>
													<Input
														type="text"
														size="medium"
														placeholder={__('Enter or Paste API Key', 'reviews-feed')}
														value={apiInputValue[pr.provider]}
														onChange={(event) => {
															setApiInputValue({
																...apiInputValue,
																[pr.provider] : event.currentTarget.value
															})
														}}
													/>
													<Button
														type="primary"
														size="medium"
														icon={currentProvider === pr.provider && addApiLoading ? 'loader' : ''}
														loading={currentProvider === pr.provider && addApiLoading}
														text={__('Add', 'reviews-feed')}
														onClick={() => {
															addApiKeyAction(pr.provider)
														}}
													/>
												</div>
												{
													currentProvider === pr.provider && apiResponseType === 'error' &&
													<span
														className="sb-modal-provider-error sb-flex sb-fs"
													>
														{__('The API key is invalid. Please try a new API key.', 'reviews-feed')}
													</span>
												}
											</div>
											<div className="sb-modal-provider-link sb-flex sb-fs">
												<a
													href={pr.link}
													target="__blank"
												>
													{pr.footer}
												</a>
												{SbUtils.printIcon('chevron-right', '', false, 9)}
											</div>
										</div>
									}
									{
										currentProvider === pr.provider && apiResponseType === 'success' &&
										<div className="sb-modal-provider-success sb-flex sb-fs  sb-svg-i">
											<div className="sb-modal-success-icon sb-flex sb-flex-center">
												{SbUtils.printIcon('success', '', false, 14)}
											</div>
											<strong>{__('API Key added successfully!', 'reviews-feed')}</strong>
										</div>
									}
								</div>
							)
						}
					})
				}
			</div>
		</div>
	)

}

export default AddApiKeyFreeRetrieverModal;