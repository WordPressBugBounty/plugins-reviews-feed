import { useContext, useEffect, useMemo, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../Common/Button';
import SbUtils from '../../Utils/SbUtils';
import Input from '../Common/Input';
import Notice from '../Global/Notice';
import Checkbox from '../Common/Checkbox';

const AddSourceModal = ( props ) => {
    const currentContext =  SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        editorTopLoader,
        editorNotification,
        sources,
        apis,
        fbModal,
        apiLimits,
        globalNotices,
        allowedTiers,
        isPro,
        upsellModal,
        bulkModal,
        freeRet,
        addSModal,
        freeRetModal
    } = useContext( currentContext );
    const [ selectedProvider, setSelectedProvider ] = useState( null );

    const [ currentStep, setCurrentStep ] = useState( 'selectProvider' );
    const [ apiKey, setApiKey ] = useState( '' );
    const [ providerIdUrl, setProviderIdUrl ] = useState( '' );
    const [ inputError, setInputError ] = useState( null ); //Will serve in case of adding Invalid API key or Wrong Place/Location URL/ID

    const [ selectedFBPages , setSelectedFBPages ] = useState( [ ] );

    const defaultApikeyNotice = {
        type: 'default',
        text : __( 'You can also skip this step, but you will need to add an API key later to keep the reviews updated.', 'sb-customizer' )
    };
    const [ apiKeyNoticeInfo, setApiKeyNoticeInfo ] = useState( defaultApikeyNotice );

    const apiKeys  = apis.apiKeys;
    const providers = isPro ? sbCustomizer.providers : sbCustomizer.providers.filter( pr => allowedTiers.tiers.includes( pr.type ) );
    const advancedProviders = isPro ? [] : sbCustomizer.providers.filter( pr => !allowedTiers.tiers.includes( pr.type ) );
    const providersWithNoApiKey = sbCustomizer.providers.map( pr => pr.onlyDetails === true && pr.type ).filter(Boolean);;
    const providersNeedApiKey = sbCustomizer.providers.map( pr => ( pr?.apiKey && pr?.apiKey === true ) && pr.type ).filter(Boolean);
    const mandatoryApiKeyProviders = sbCustomizer.providers.map( pr => ( pr?.mandatoryApiKey && pr?.mandatoryApiKey === true ) && pr.type ).filter(Boolean);;
    const needsApiKey = providersNeedApiKey.includes( selectedProvider ) || mandatoryApiKeyProviders.includes( selectedProvider );
    const selectedProviderInfo = providers.filter( provider =>  provider.type === selectedProvider)[0];
    const [ providerUrlIdNotice, setProviderUrlIdNotice ] = useState( null );

    const [ tiersInfo, setTiersInfo ] = useState(sbCustomizer?.pluginStatus?.tiers_info);


    const setAPIKeyNotice = ( type = 'default') => {
        let noticeMessage = defaultApikeyNotice;
        if(type === 'sourceLimitExceeded'){
            noticeMessage = {
                type: 'error',
                text : __( 'You have reached the maximum sources limit, Please enter a valid API key to retrieve new sources.', 'sb-customizer' ) + '<br/>' + __( 'Are you using a local host? API keys may be required for all review retrieving.', 'sb-customizer' )
            };
        }
        setApiKeyNoticeInfo( noticeMessage );
    }



    const selectFBPages = ( pageID ) => {
        let fbPages = [...selectedFBPages];
        if( ! fbPages.includes( pageID ) ){
            fbPages.push( pageID )
        }else{
            fbPages.splice( fbPages.indexOf( pageID ), 1 );
        }
        setSelectedFBPages( [...fbPages] )
    }

    const getSelectedPagesInfo = (  ) => {
        return selectedFBPages.map( pageID => {
            let page = SbUtils.findElementById( sbCustomizer.newSourceData.pages , 'id', pageID );
            return {
                id : page.id,
                name : page.name,
                rating : page?.overall_star_rating ? page?.overall_star_rating : 0,
                total_rating : page?.total_rating ? page?.total_rating : 0,
                provider : 'facebook',
                access_token : page.access_token,
                url : 'https://www.facebook.com/' + page.id,
            }
        });
    }

    const addFacebookPagesSource = () => {
        if( selectedFBPages.length > 0 ){
            const formData = {
                action                  : 'sbr_feed_saver_manager_add_facebook_souce',
                selectedFacebookPages   : JSON.stringify( getSelectedPagesInfo() )
            },
            notificationsContent = {
                success : {
                    icon : 'success',
                    text : __('Facebook Sources Added', 'sb-customizer')
                }
            };
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => {
                    if( data?.sourcesList !== undefined ){
                        sources.setSourcesList( data?.sourcesList );
                    }
                    cancelClick()
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
    }

    const connectFacebookActiveMemo = useMemo( () => {
        if( fbModal.connectFacebookActive === true ){
            setCurrentStep('addSourceDetails')
            setSelectedProvider( 'facebook' )
            return true;
        }
        return false;
    },[ fbModal ]);

    const addSourcesSteps = [
        {
            id : 'selectProvider',
            heading : __( 'Select Source Type', 'sb-customizer' )
        },
        {
            id : 'addApiKey',
            heading : __( 'Add API Key', 'sb-customizer' ),
            condition : needsApiKey && apiKeys[selectedProvider] === undefined
        },
        {
            id : 'addSourceDetails',
            heading : __( 'Add Source Details', 'sb-customizer' )
        }
    ];

    const navTopClick = ( stepID ) => {
        if( stepID === 'addApiKey' &&  selectedProvider !== undefined){
            setCurrentStep('addApiKey')
        }
        if( stepID === 'selectProvider'){
            setCurrentStep('selectProvider')
        }
    }

    const providerUrlIdNoticeList = {
        'wordpress.org' : __('Your URL must contain "/themes/" or "/plugins/". Examples: <a href="#">https://wordpress.org/plugins/instagram-feed/</a>, <a href="#">https://wordpress.org/themes/twentytwentythree/</a>', 'sb-customizer')
    }

    const checkValidIDUrl = () => {
        switch (selectedProvider) {
            case 'wordpress.org':
                return providerIdUrl.includes('plugins/') || providerIdUrl.includes('themes/')
            default:
                return true;
        }
    }

    const finishStepClick = () => {
        if( SbUtils.checkNotEmpty( providerIdUrl ) ){
            if( ! checkValidIDUrl() ){
                setProviderUrlIdNotice(providerUrlIdNoticeList[selectedProvider])
               setTimeout( () => {
                    setProviderUrlIdNotice(null)
               }, 60000)
               return false;
            }
            setProviderUrlIdNotice(null)
            const formData = {
                action          : 'sbr_feed_saver_manager_add_source',
                provider        : selectedProvider,
                apiKey          : SbUtils.checkNotEmpty( apiKey ) !== false ? apiKey : null,
                providerIdUrl   : providerIdUrl
            };
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => {
                    editorTopLoader.setLoader( false );

                    if( data?.error !== undefined ){
                        let errorMessage = __('Unknown error occured!', 'sb-customizer');
                        if( data?.error ===  'sourceLimitExceeded'){
                            let isProviderNeedAPI = ! ['trustpilot', 'wordpress.org'].includes(selectedProvider);
                            errorMessage = isProviderNeedAPI
                            ? __('Source Limit Exceeded. Please enter API Key.', 'sb-customizer')
                            : __('Source Limit Exceeded.', 'sb-customizer');

                            setAPIKeyNotice( 'sourceLimitExceeded' );
                            setTimeout(() => {
                                setCurrentStep( isProviderNeedAPI ? 'addApiKey' : 'selectProvider' )
                            }, 1000);
                        }

                        const errorUnknown = {
                            type : 'error',
                            icon : 'notice',
                            text : errorMessage
                        }
                        SbUtils.applyNotification( errorUnknown , editorNotification )
                    }else{
                        const duration = data?.apikey !== undefined ? 500 : 0;
                        processAPIKeyReturn( data?.apikey );
                        setTimeout(() => {
                            processLocationReturn( data?.placeId, data?.message );
                        }, duration);

                        if( data?.apiKeys !== undefined ){
                            apis.setApiKeys( data?.apiKeys );
                        }
                        if( data?.sourcesList !== undefined ){
                            sources.setSourcesList( data?.sourcesList );
                        }
                        if( data?.newAddedSource !== undefined ){
                            localStorage.setItem('newAddedSourceId', data?.newAddedSource?.id)
                        }
                        if ( data?.bulkStarted !== undefined){
                            const errorUnknown = {
                                type : 'success',
                                icon : 'success',
                                text : __('Fetching reviews history in the background!', 'reviews-feed'),
                                time : 7000
                            }
                             setTimeout(() => {
                                SbUtils.applyNotification( errorUnknown , editorNotification )
                            }, 3200);
                        }
                    }
                    if( data?.apiKeyLimits !== undefined ){
                        const newApiLimits = [...data?.apiKeyLimits]
                        apiLimits.setApiKeyLimits( newApiLimits );
                    }
                    if( data?.pluginNotices !== undefined
                        && data?.pluginNotices.length > 0
                    ){
                        const newPluginNotices = [...data?.pluginNotices]
                        globalNotices.setPluginNotices( newPluginNotices );
                    }
                    if (data?.freeRetrieverData) {
                        freeRet.setFreeRetrieverData(data?.freeRetrieverData)
                    }
                },
                editorTopLoader,
                editorNotification,
                null
            )
        }
    }

    const setReturnError = ( errorType ) => {
        setInputError( errorType );
        setTimeout(() => {
            setInputError( null );
        }, 3500);
    }

    const processAPIKeyReturn = ( apiKeyReturn ) => {
        if( apiKeyReturn !== undefined ){
            let notificationApiKey = {};
            if( apiKeyReturn === 'valid' ){
                notificationApiKey = {
                    icon : 'success',
                    text : __('API Key Added', 'sb-customizer')
                }
            }
            else if( apiKeyReturn === 'invalid' ){
                setCurrentStep( 'addApiKey' );
                setReturnError( 'errorAPIKey' );
                notificationApiKey = {
                    type : 'error',
                    icon : 'notice',
                    text : __('Invalid API Key', 'sb-customizer')
                }
            }
            SbUtils.applyNotification( notificationApiKey , editorNotification )
        }

    }

    const processLocationReturn = ( placeId, message ) => {
        let notificationPlaceId = {};
        if( placeId !== undefined ){
            if( placeId === 'invalid' ){
                setCurrentStep( 'addSourceDetails' );
                setReturnError( 'errorLocation' );
                notificationPlaceId = {
                    type : 'error',
                    icon : 'notice',
                    text : __('Invalid Location ID or URL', 'sb-customizer'),
                    time : 15000
                }
            }
        }
        if( message !== undefined  && message === 'addedSource') {
            if (
                freeRet?.freeRetrieverData?.providers.includes(selectedProvider)
                && !SbUtils.checkAPIKeyExists(selectedProvider, apis)
            ) {
                applyRetrievalResponseNotice()
            } else {
                cancelClick();
            }
            notificationPlaceId = {
                icon : 'success',
                text : __('Source added successfully', 'sb-customizer')
            }
        }


        SbUtils.applyNotification( notificationPlaceId , editorNotification )
    }

    const checkFreeRetrievalStep = (provider) => {
        if (SbUtils.checkAPIKeyExists(provider, apis)) {
            setCurrentStep('addSourceDetails')
        } else {
            const shouldLimit               = SbUtils.shouldLimiFreeRetrieval(provider, apis, freeRet);
            const shouldLimitFreeUserEmail  = SbUtils.shouldLimiFreeUserEmail(provider, apis, freeRet);
            if (shouldLimit === true) {
                addSModal.setModalType('freeRetriever')
            } else if(shouldLimitFreeUserEmail) {
                freeRetModal.setRetModalType("verifyEmail")
                addSModal.setModalType('freeRetriever')
            }
            else {
                addSModal.setModalType('addSource')
                setCurrentStep('addSourceDetails')
            }
        }
    }

    const applyRetrievalResponseNotice = () => {
        addSModal.setModalType('freeRetriever')
        freeRetModal?.setRetModalType('sourceAdded')
    }

    const nextStepClick = () => {
        switch (currentStep) {
            case 'selectProvider':
                if (!freeRet?.freeRetrieverData?.providers.includes(selectedProvider)) {
                    const skipApiKey = apiKeys[selectedProvider] !== undefined || providersWithNoApiKey.includes(selectedProvider);
                    setCurrentStep( skipApiKey ? 'addSourceDetails' : 'addApiKey')
                } else {
                    checkFreeRetrievalStep(selectedProvider)
                }
                //
            break;
            case 'addApiKey':
                if( selectedProvider !== 'google' ){

                }
                setCurrentStep('addSourceDetails')
            break;
            case 'addSourceDetails' :
                finishStepClick()
            break;
            default:
                setCurrentStep('selectProvider')
            break;
        }
    }

    const connectWithFacebookAction = () => {
        window.fbConnectProcess = true;
        const appendURL = sbCustomizer.isFeedEditor ?
                        sbCustomizer.connectFBUrls.stateURL + ',feed_id=' + sbCustomizer.feedData.feed_info.id : sbCustomizer.connectFBUrls.stateURL;
        const params = sbCustomizer.connectFBUrls.page;
        const ifConnectURL = params.connect;
        const urlParams = {
            'wordpress_user' : params.wordpress_user,
            'v' : params.v,
            'vn' : params.vn,
            'cff_con' : params.cff_con,
            'state' : "{'{url=" + appendURL + "}'}"
        };
        if(params.sw_feed) {
            urlParams['sw-feed'] = 'true';
        }
        let form = document.createElement('form');
            form.method = 'POST';
            form.action = ifConnectURL;

            for (const param in urlParams) {
                if (urlParams.hasOwnProperty(param)) {
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = param;
                    input.value = urlParams[param];
                    form.appendChild(input);
                }
            }

            document.body.appendChild(form);
            form.submit();
    }


    const getTooltipText = ( provider ) => {
        const tiersListText = (tiersInfo !== undefined && tiersInfo[provider.type] !== undefined && tiersInfo[provider.type] === 3) ? __('"Elite"', 'sb-customizer' ) : __('"Plus" and "Elite"', 'sb-customizer' );

        return `<p>
                    ${provider.name}  ${__( 'reviews are available for the', 'sb-customizer') +' '+ tiersListText +' '+  __('license tiers only, please upgrade your license on ', 'sb-customizer' ) }
                <a
                    href="https://smashballoon.com/extensions/reviews-feed-pro/?reviews&utm_campaign=reviews-pro&utm_source=feedcreate&utm_medium=connect&utm_content=upgrade"
                    rel="noreferrer"
                    target="_blank"
                > SmashBalloon </a>
                ${__( 'in order to connect a ', 'sb-customizer' ) +  provider.name  + ' ' + __( 'source.', 'sb-customizer' ) }
            </p>`

    }

    const cancelClick = () => {
        props.onCancel();
    }

    useEffect(() => {
        if (
            props?.onSuccessApiKey !== undefined
            && props?.onSuccessApiKey !== null
        ) {
            setSelectedProvider(props?.onSuccessApiKey)
            setCurrentStep('addSourceDetails')
        }
    }, [props?.onSuccessApiKey])

    let navInd = 0;
    return (
        <div className="sb-addsource-modal sb-fs" data-pro={isPro}>
            <div className="sb-addsource-modal-heading sb-fs">
                <h4 className='sb-h4'>{ __( 'Add Sources', 'sb-customizer' ) }</h4>
            </div>
            <div className='sb-addsource-modal-navtop sb-fs'>
                {
                   addSourcesSteps.map( ( step, index ) => {
                        const showNavItem = step?.condition === undefined || step.condition;
                        navInd = showNavItem ? navInd + 1 : navInd;
                        return (
                            ( showNavItem ) &&
                            <div
                                className='sb-addsource-modal-navitem'
                                data-active={currentStep === step.id}
                                key={ index }
                                onClick={ () => {
                                    navTopClick( step.id )
                                } }
                            >
                                <span className='sb-addsource-modal-navitem-num'>{ navInd }</span>
                                <span className={ (currentStep === step.id ? 'sb-dark-text ' : 'sb-dark2-text ' )+ 'sb-text-tiny sb-bold'}>{ step.heading }</span>
                            </div>
                        )
                   } )
                }
            </div>
            {
                currentStep === 'selectProvider' &&
                <>
                    <div className='sb-addsource-modal-providers-list sb-fs' data-type="default">
                        {
                            providers.map( provider => {
                                return (
                                    <div
                                        className='sb-addsource-modal-provider-item sb-fs'
                                        key={ provider.type }
                                        data-disabled={ ! allowedTiers.tiers.includes( provider.type ) }
                                        onClick={ () => {
                                            if( allowedTiers.tiers.includes( provider.type ) ){
                                                const sProvider = provider.type === selectedProvider ? null : provider.type;
                                                setSelectedProvider( sProvider )
                                                if(sProvider !== 'google'){
                                                    setAPIKeyNotice( apiLimits?.apiKeyLimits.includes( sProvider ) ? 'sourceLimitExceeded' : 'default' );
                                                }
                                            }
                                        }}
                                        data-active={ provider.type === selectedProvider}
                                    >
                                        {
                                            !allowedTiers.tiers.includes( provider.type ) &&
                                            SbUtils.printTooltip( getTooltipText( provider ) , {
                                                type : 'white',
                                                textAlign : 'center',
                                                width : 'full',
                                                replaceText : false
                                            } )
                                        }
                                        { SbUtils.printIcon( provider.type + '-provider' ) }
                                        <span className='sb-text-small'>{ provider.name }</span>
                                    </div>
                                )
                            } )
                        }
                    </div>
                    {
                        !isPro &&
                        <>
                            <span className='sb-addsource-advanced-hd sb-fs sb-bold sb-text-tiny sb-light-text'>{__('ADVANCED SOURCES', 'sb-customizer')}</span>
                            <div className='sb-addsource-modal-providers-list sb-fs' data-type="advanced">
                                {
                                    advancedProviders.map( provider => {
                                        return (
                                            <div
                                                className='sb-addsource-modal-provider-item sb-fs'
                                                key={ provider.type }
                                                onClick={ () => {
                                                    upsellModal.setUpsellActive( provider.type + 'Provider')
                                                }}
                                            >
                                                { SbUtils.printIcon( provider.type + '-provider' ) }
                                                <span className='sb-text-small sb-addsource-advanced-provider-name'>
                                                    <span>{ provider.name }</span>
                                                    { SbUtils.printIcon( 'rocket', 'sb-addsource-advanced-icon', false, 12 ) }
                                                </span>
                                            </div>
                                        )
                                    } )
                                }
                            </div>
                        </>
                    }

                    <div className='sb-collection-srmodal-ctn sb-fs'>
                        <div className='sb-collection-srmodal-ins sb-fs'>
                            {SbUtils.printIcon('collection-provider', '', false, 32)}
                            <div className='sb-collection-srmodal-text'>
                                <strong>{ __( 'Handpick reviews with collections', 'reviews-feed' ) }</strong>
                                <p>{ __( 'Select among your reviews and organize them into a collection. Manually create a review for complete control. Once a collection is created, it will appear as a feed source.', 'reviews-feed' ) }</p>
                            </div>
                            <Button
                                type='secondary'
                                size='small'
                                icon='plus'
                                boxshadow='false'
                                iconSize='10'
                                text={ __( 'Create Collection', 'sb-customizer' ) }
                                link={sbCustomizer?.collectionsPageUrl}
                            />
                        </div>
                    </div>

                    {
                        ( apiLimits.apiKeyLimits.includes('wordpress.org') || apiLimits.apiKeyLimits.includes('trustpilot') ) &&
                        <div className='sb-reached-notice-limit-ctn sb-fs'>
                            <Notice
                                type='error'
                                text={
                                    __( 'You have reached the allowed the number of sources for ', 'sb-customizer') +
                                    (apiLimits.apiKeyLimits.includes('wordpress.org') ? 'WordPress.Org' : '') +
                                    ((apiLimits.apiKeyLimits.includes('wordpress.org') && apiLimits.apiKeyLimits.includes('trustpilot') ) ? ' & ' : '') +
                                    (apiLimits.apiKeyLimits.includes('trustpilot') ? 'Trustpilot' : '') +
                                    '.'
                                }
                            />
                        </div>
                    }
                </>
            }

            {
                currentStep === 'addApiKey' && !['trustpilot', 'wordpress.org'].includes(selectedProvider) &&
                <div className='sb-addsource-modal-apikey-ctn sb-fs'>
                    <div className='sb-addsource-modal-apikey-heading sb-fs'>
                        <span className='sb-standard-p sb-bold '>
                            { __( 'Add an API Key for ', 'sb-customizer' ) + selectedProviderInfo.name }
                        </span>
                        <p className='sb-small-p sb-fs'>
                            {
                                selectedProviderInfo.type !== 'tripadvisor' &&
                                __( 'API key gives us permission to fetch data from ', 'sb-customizer' )
                                + selectedProviderInfo.name +
                                __( '. You only need to add this once. For any future ', 'sb-customizer' )
                                + selectedProviderInfo.name +
                                __( ' accounts, we will use the same API key.', 'sb-customizer' )
                            }
                            {
                                selectedProviderInfo.type === 'tripadvisor' &&
                                <span>
                                    { __( 'The TripAdvisor API has a significant monthly cost. Your feeds will still update without entering an API key but you can choose to add your own.', 'sb-customizer' ) }<br/>
                                </span>
                            }
                            {
                                selectedProviderInfo?.docLink &&
                                <>
                                    <br/>
                                    <a
                                        href={selectedProviderInfo?.docLink}
                                        target='_blank'
                                        rel='noreferrer'
                                    >
                                        { __( 'How to Create an API key.', 'sb-customizer' ) }<br/>
                                    </a>
                                </>
                            }
                        </p>
                    </div>
                    <div
                        className='sb-addsource-modal-apikey-input sb-fs'
                        data-error={ inputError === 'errorAPIKey' }
                    >
                        <Input
                            size='large'
                            placeholder={ __( 'Add API Key', 'sb-customizer' ) }
                            leadingIcon='key'
                            disablebg='true'
                            value={ apiKey }
                            onChange = { ( event ) =>
                                setApiKey( event.currentTarget.value )
                            }
                        />
                    </div>
                    {
                        selectedProvider !== 'google' && selectedProvider !== 'tripadvisor' &&
                        <Notice
                            type={ apiKeyNoticeInfo?.type }
                            text={ apiKeyNoticeInfo?.text }
                        />
                    }
                </div>
            }
            {
                currentStep === 'addSourceDetails' && selectedProvider !== 'facebook' && ! connectFacebookActiveMemo &&
                <div className='sb-addsource-modal-apikey-ctn sb-fs' data-type='addsourcedetails'>
                    <div className='sb-addsource-modal-apikey-heading sb-fs'>
                        <span className='sb-standard-p sb-bold '>{ selectedProviderInfo?.heading }</span>
                        <p className='sb-small-p sb-fs'>
                            {__( 'This helps us identify which page to fetch reviews from', 'sb-customizer' )}
                            {
                                selectedProvider === 'google' &&
                                <span>
                                    <br/>
                                    {__( 'Find your source\'s', 'sb-customizer' )} <a href='https://developers.google.com/maps/documentation/places/web-service/place-id' target='blank'> {__( 'place ID here', 'sb-customizer' )}</a>
                                </span>
                            }
                        </p>
                    </div>
                    <div
                        className='sb-addsource-modal-apikey-input sb-fs'
                        data-error={ inputError === 'errorLocation' }
                    >
                        <Input
                            size='large'
                            placeholder={ selectedProviderInfo?.placeholder }
                            leadingIcon='globe'
                            disablebg='true'
                            value={ providerIdUrl }
                            onChange = { ( event ) =>
                                setProviderIdUrl( event.currentTarget.value )
                            }
                        />
                    </div>
                    {
                        providerUrlIdNotice !== null &&
                        <Notice
                            type="default"
                            text={ providerUrlIdNotice }
                        />
                    }
                </div>
            }
            {
                currentStep === 'addSourceDetails' && selectedProvider === 'facebook' && connectFacebookActiveMemo &&
                <div className='sb-addsource-modal-apikey-ctn sb-fs' data-type='addsourcedetails'>
                    <div className='sb-fbconnect-top sb-text-tiny sb-dark2-text sb-fs'>
                        { __( 'Showing', 'sb-customizer' ) } <strong className='sb-dark-text'>{ __( 'Facebook Pages', 'sb-customizer' ) }</strong> { __( 'connected to', 'sb-customizer' ) }
                        <img src={sbCustomizer?.newSourceData?.user?.picture?.data?.url } alt='Profile' /> <strong className='sb-dark-text'>{sbCustomizer?.newSourceData?.user?.name }</strong>
                    </div>
                    <div className='sb-fbconnect-list sb-fs'>
                        {
                            sbCustomizer?.newSourceData?.pages.map( page => {
                                return (
                                    <div
                                        className='sb-fbconnect-singlepage'
                                        data-active={ selectedFBPages.includes( page.id ) === true  }
                                        key={ page.id }
                                        onClick = { () =>
                                            selectFBPages( page.id )
                                        }
                                    >
                                        <Checkbox
                                            value={ selectedFBPages.includes( page.id ) }
                                            enabled={ true }
                                            onChange = { () =>
                                                selectFBPages( page.id )
                                            }
                                        />
                                        <img src={'https://graph.facebook.com/' + page.id + '/picture'} alt={page?.name}/>
                                        <strong className='sb-text-small sb-bold'>
                                            { page?.name }
                                        </strong>
                                    </div>
                                )
                            } )
                        }
                    </div>
                </div>

            }

            <div
                className='sb-addsource-modal-provider-btn sb-fs'
            >
                {
                    (
                        (
                            ! apiLimits?.apiKeyLimits.includes( selectedProvider ) || currentStep !== 'addApiKey' )
                            && ((mandatoryApiKeyProviders.includes(selectedProvider) && currentStep !== 'addApiKey' ) || !mandatoryApiKeyProviders.includes(selectedProvider))
                        ) &&
                    <Button
                        type='secondary'
                        size='medium'
                        text={ currentStep === 'addApiKey' ? __( 'Skip', 'sb-customizer' ) : __( 'Cancel', 'sb-customizer' ) }
                        onClick={ () => {
                        if( currentStep === 'addApiKey' ){
                            nextStepClick();
                        }else{
                            cancelClick()
                        }
                        } }
                    />
                }
                {
                    //Check if we need to display the Next Button or Not
                    (
                        (
                            needsApiKey &&
                            (
                                ( !apiLimits?.apiKeyLimits.includes( selectedProvider ) && !mandatoryApiKeyProviders.includes(selectedProvider) ) ||
                                currentStep !== 'addApiKey' ||
                                (SbUtils.checkNotEmpty( apiKey ) && currentStep === 'addApiKey')
                            )
                        )
                        || providersWithNoApiKey.includes(selectedProvider) //Providers with No API Kedy
                        || freeRet?.freeRetrieverData?.providers.includes(selectedProvider) //V2 Yelp & Google No API Key
                    )
                    &&
                    <Button
                        type='primary'
                        size='medium'
                        icon='chevron-right'
                        icon-position='right'
                        iconSize='8'
                        text={
                            currentStep === 'addSourceDetails'
                            ? __( 'Finish', 'sb-customizer' )
                            : __( 'Next', 'sb-customizer' )
                        }
                        onClick={ () => {
                            nextStepClick()
                        } }
                    />
                }
                {
                    selectedProvider === 'facebook' && ! connectFacebookActiveMemo &&
                    <Button
                        type='primary'
                        size='medium'
                        icon='facebook'
                        iconSize='22'
                        text={ __( 'Connect with Facebook', 'sb-customizer' ) }
                        onClick={ () => {
                            connectWithFacebookAction()
                        } }
                    />
                }
                {
                    selectedProvider === 'facebook' && connectFacebookActiveMemo &&
                    <Button
                        type='primary'
                        size='medium'
                        icon='plus'
                        iconSize='12'
                        text={ __( 'Add Pages as Source', 'sb-customizer' ) }
                        onClick={ () => {
                            addFacebookPagesSource()
                        } }
                    />
                }
            </div>
        </div>
    )
}

export default AddSourceModal;